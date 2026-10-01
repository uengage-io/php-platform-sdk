<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Alerts;

use Throwable;

/**
 * SQS FIFO transport backed by `aws/aws-sdk-php`.
 *
 * Same dependency stance as {@see \Uengage\PlatformSdk\Events\AwsSnsPublisher}:
 * the AWS SDK is a *suggested*, not a required, dependency, so consumers
 * that never raise an alert are not dragged onto it (and its PHP >= 7.2.5
 * floor). When the class is absent, `sendBatch()` throws AlertsException;
 * AlertsClient catches it and fails open, so a host that forgot the
 * dependency logs an error rather than breaking its request.
 *
 * Every message goes out with
 *   MessageGroupId         = `${parentId}#${businessId}#${type}`
 *   MessageDeduplicationId = the message's ULID `id`
 * per the contract in packages/platform-sdk/src/alerts/schema.ts. The
 * group id is what lets the alerts consumer handle one alert's messages
 * strictly in order; the dedup id makes an SDK-level retry of the same
 * raise() harmless within SQS's 5-minute dedup window.
 *
 * Short timeouts by design: the send happens at request end, on a worker
 * that other requests are waiting for.
 */
class AwsSqsSender implements SqsSenderInterface
{
    const SQS_CLIENT_CLASS = 'Aws\\Sqs\\SqsClient';

    /** Connect timeout, seconds. */
    const CONNECT_TIMEOUT = 1.0;

    /** Total request timeout, seconds. */
    const TIMEOUT = 2.0;

    /**
     * SQS limits one message AND the sum of a SendMessageBatch's bodies to
     * 256 KiB. Alerts are small (the contract caps context at 50 x 1 KiB),
     * so this is a safety net, not a routine path: an oversized message is
     * dropped on its own, and a batch that would exceed the total is split.
     */
    const MAX_BATCH_BYTES = 262144;

    /** @var string|null */
    private $region;

    /** @var object|null Lazily-built Aws\Sqs\SqsClient. */
    private $client;

    /** @var int Pause before retrying server-side batch failures, in microseconds. */
    private $retryDelayMicros;

    /**
     * @param string|null $region AWS region. When null it is read from the
     *                            queue URL host (sqs.<region>.amazonaws.com or
     *                            <region>.queue.amazonaws.com), else AWS_REGION,
     *                            else AWS_DEFAULT_REGION.
     * @param object|null $client Pre-built SqsClient, if the host app has one.
     * @param int $retryDelayMicros Pause before the one retry of entries SQS
     *                              failed on its side (SenderFault false).
     */
    public function __construct(?string $region = null, $client = null, int $retryDelayMicros = 100000)
    {
        $this->region = $region;
        $this->client = $client;
        $this->retryDelayMicros = $retryDelayMicros;
    }

    /** True when `aws/aws-sdk-php` is installed and usable. */
    public static function isAvailable(): bool
    {
        return class_exists(self::SQS_CLIENT_CLASS);
    }

    /**
     * `https://sqs.ap-south-1.amazonaws.com/123/alerts-uat.fifo` -> `ap-south-1`.
     */
    public static function regionFromQueueUrl(string $queueUrl): ?string
    {
        $host = parse_url($queueUrl, PHP_URL_HOST);
        if (!is_string($host)) {
            return null;
        }
        // sqs.<region>.amazonaws.com, or the legacy <region>.queue.amazonaws.com.
        if (
            preg_match('/^sqs\.([a-z0-9-]+)\.amazonaws\.com(\.cn)?$/i', $host, $m) === 1
            || preg_match('/^([a-z0-9-]+)\.queue\.amazonaws\.com(\.cn)?$/i', $host, $m) === 1
        ) {
            return strtolower($m[1]);
        }
        return null;
    }

    /**
     * `scheme://host[:port]` of a queue URL whose host is not an AWS SQS host,
     * else null (AWS hosts use the SDK's own endpoint resolution).
     */
    public static function localEndpoint(string $queueUrl): ?string
    {
        if (self::regionFromQueueUrl($queueUrl) !== null) {
            return null;
        }
        $parts = parse_url($queueUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (preg_match('/\.amazonaws\.com(\.cn)?$/i', $parts['host']) === 1) {
            return null;
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * {@inheritdoc}
     */
    public function sendBatch(string $queueUrl, array $messages): array
    {
        if (empty($messages)) {
            return [];
        }

        $client = $this->client($queueUrl);

        // Encode each message on its own BEFORE assembling the batch, so one
        // bad byte (json_encode returns false on malformed UTF-8) fails that
        // entry alone instead of the whole SendMessageBatch.
        $failed = [];
        $batches = [];
        $current = [];
        $currentBytes = 0;
        foreach ($messages as $message) {
            $id = (string) $message['id'];
            $body = self::encodeMessage($message);
            if ($body === null) {
                $failed[] = $id;
                error_log(sprintf(
                    '[uengage-platform-sdk] dropping alert %s (%s): message is not encodable as JSON (%s)',
                    $id,
                    $message['type'],
                    json_last_error_msg()
                ));
                continue;
            }
            $bytes = strlen($body);
            if ($bytes > self::MAX_BATCH_BYTES) {
                $failed[] = $id;
                error_log(sprintf(
                    '[uengage-platform-sdk] dropping alert %s (%s): %d bytes exceeds the SQS 256KB '
                        . 'message limit',
                    $id,
                    $message['type'],
                    $bytes
                ));
                continue;
            }
            if (!empty($current) && $currentBytes + $bytes > self::MAX_BATCH_BYTES) {
                $batches[] = $current;
                $current = [];
                $currentBytes = 0;
            }
            $current[] = [
                'Id' => $id,
                'MessageBody' => $body,
                'MessageGroupId' => AlertsClient::messageGroupId($message),
                'MessageDeduplicationId' => $id,
            ];
            $currentBytes += $bytes;
        }
        if (!empty($current)) {
            $batches[] = $current;
        }

        foreach ($batches as $index => $entries) {
            try {
                $result = $client->sendMessageBatch([
                    'QueueUrl' => $queueUrl,
                    'Entries' => $entries,
                ]);
            } catch (Throwable $e) {
                // Nothing from this sub-batch onwards was sent. Report the
                // later sub-batches as failed too, so the caller's count is
                // right, and surface the transport error only when nothing
                // at all got through.
                if ($index === 0) {
                    throw new AlertsException('SQS SendMessageBatch failed: ' . $e->getMessage(), 0, $e);
                }
                foreach (array_slice($batches, $index) as $rest) {
                    foreach ($rest as $entry) {
                        $failed[] = $entry['Id'];
                    }
                }
                error_log('[uengage-platform-sdk] SQS SendMessageBatch failed: ' . $e->getMessage());
                return $failed;
            }
            $retry = [];
            foreach (self::failedEntries($result) as $entry) {
                $id = (string) $entry['Id'];
                // SenderFault false: throttling or an SQS-side error, worth
                // one more try. Anything else (or unknown) is the caller's.
                if (isset($entry['SenderFault']) && $entry['SenderFault'] === false) {
                    foreach ($entries as $sent) {
                        if ($sent['Id'] === $id) {
                            $retry[] = $sent;
                        }
                    }
                } else {
                    $failed[] = $id;
                }
            }
            if (!empty($retry)) {
                foreach ($this->retryOnce($client, $queueUrl, $retry) as $id) {
                    $failed[] = $id;
                }
            }
        }
        return $failed;
    }

    /**
     * Resend entries SQS failed on its side, once. Returns the ids still failing.
     *
     * @param object $client
     * @param array<int, array> $entries
     * @return string[]
     */
    private function retryOnce($client, string $queueUrl, array $entries): array
    {
        if ($this->retryDelayMicros > 0) {
            usleep($this->retryDelayMicros);
        }
        try {
            $result = $client->sendMessageBatch(['QueueUrl' => $queueUrl, 'Entries' => $entries]);
        } catch (Throwable $e) {
            error_log('[uengage-platform-sdk] SQS SendMessageBatch retry failed: ' . $e->getMessage());
            return array_map(function (array $entry) {
                return (string) $entry['Id'];
            }, $entries);
        }
        return array_map(function (array $entry) {
            return (string) $entry['Id'];
        }, self::failedEntries($result));
    }

    /**
     * `Failed` entries of a SendMessageBatch result that carry an Id.
     *
     * @param mixed $result
     * @return array<int, array>
     */
    private static function failedEntries($result): array
    {
        $entries = isset($result['Failed']) && is_array($result['Failed']) ? $result['Failed'] : [];
        return array_values(array_filter($entries, function ($entry) {
            return is_array($entry) && isset($entry['Id']);
        }));
    }

    /**
     * JSON-encode one message, or null if it cannot be encoded. Retries
     * with JSON_INVALID_UTF8_SUBSTITUTE on PHP 7.2+ (the package supports
     * 7.1, where the flag does not exist).
     */
    private static function encodeMessage(array $message): ?string
    {
        $body = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body !== false) {
            return $body;
        }
        if (PHP_VERSION_ID >= 70200 && json_last_error() === JSON_ERROR_UTF8) {
            $body = json_encode(
                $message,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($body !== false) {
                return $body;
            }
        }
        return null;
    }

    /**
     * @return object
     */
    private function client(string $queueUrl)
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if (!self::isAvailable()) {
            throw new AlertsException(
                'aws/aws-sdk-php is not installed. Run `composer require aws/aws-sdk-php` in the '
                . 'application that raises alerts, or pass your own SqsSenderInterface.'
            );
        }
        $class = self::SQS_CLIENT_CLASS;
        $args = [
            'version' => '2012-11-05',
            'http' => [
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
            ],
            // One attempt beyond the first. The shutdown handler is not the
            // place to sit in a retry loop.
            'retries' => 1,
        ];
        $region = $this->region !== null && $this->region !== ''
            ? $this->region
            : self::regionFromQueueUrl($queueUrl);
        foreach (['AWS_REGION', 'AWS_DEFAULT_REGION'] as $var) {
            if ($region === null) {
                $value = getenv($var);
                $region = $value !== false && $value !== '' ? $value : null;
            }
        }
        if ($region !== null) {
            $args['region'] = $region;
        }
        // A queue URL that is not an AWS SQS host (LocalStack, ElasticMQ) is
        // also the endpoint: the SqsClient otherwise derives the endpoint from
        // the region and would send to real AWS.
        $endpoint = self::localEndpoint($queueUrl);
        if ($endpoint !== null) {
            $args['endpoint'] = $endpoint;
        }
        $this->client = new $class($args);
        return $this->client;
    }
}
