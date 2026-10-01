<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Events;

use Throwable;

/**
 * SNS transport backed by `aws/aws-sdk-php`.
 *
 * That package is a *suggested*, not a required, dependency of this SDK:
 * this library is installed by every legacy consumer (zones, business,
 * audit, ...), most of which never publish an event, and a hard
 * requirement would drag the AWS SDK - and its PHP >= 7.2.5 floor - into
 * all of them. Applications that publish events add
 * `composer require aws/aws-sdk-php` themselves.
 *
 * When the class is absent, `publishBatch()` throws
 * `EventsException`; EventsClient catches it and fails open, so a host
 * that forgot the dependency logs an error rather than breaking an order
 * flow.
 *
 * Short timeouts by design: the publish happens on the request path,
 * after the DB write. A slow bus must never become slow checkout.
 */
class AwsSnsPublisher implements SnsPublisherInterface
{
    const SNS_CLIENT_CLASS = 'Aws\\Sns\\SnsClient';

    /** Connect timeout, seconds. */
    const CONNECT_TIMEOUT = 1.0;

    /** Total request timeout, seconds. */
    const TIMEOUT = 2.0;

    /**
     * SNS caps a single message at 256 KB. An oversized envelope is a
     * call-site bug (someone attached a whole order document), so it is
     * dropped with a log rather than failing its nine batch siblings.
     */
    const MAX_MESSAGE_BYTES = 262144;

    /** @var string|null */
    private $region;

    /** @var object|null Lazily-built Aws\Sns\SnsClient. */
    private $client;

    /**
     * @param string|null $region AWS region. Falls back to the SDK's own
     *                            resolution (AWS_REGION / instance metadata)
     *                            when null.
     * @param object|null $client Pre-built SnsClient, if the host app has one.
     */
    public function __construct(?string $region = null, $client = null)
    {
        $this->region = $region;
        $this->client = $client;
    }

    /**
     * True when `aws/aws-sdk-php` is installed and usable.
     */
    public static function isAvailable(): bool
    {
        return class_exists(self::SNS_CLIENT_CLASS);
    }

    /**
     * {@inheritdoc}
     */
    public function publishBatch(string $topicArn, array $envelopes): array
    {
        if (empty($envelopes)) {
            return [];
        }

        $client = $this->client();

        // Encode each envelope on its own BEFORE assembling the batch.
        //
        // json_encode returns false on malformed UTF-8, which is realistic
        // here: legacy order payloads carry customer names straight out of
        // the legacy DB. A false slipped into an entry makes the AWS SDK
        // reject the entire PublishBatch, so one bad byte would fail all
        // ten siblings. Encoding first lets the bad entry be dropped on its
        // own and the other nine go out.
        $entries = [];
        $failed = [];
        foreach ($envelopes as $envelope) {
            $message = self::encodeMessage($envelope);
            if ($message === null) {
                $failed[] = (string) $envelope['id'];
                error_log(sprintf(
                    '[uengage-platform-sdk] dropping event %s (%s): payload is not encodable as '
                        . 'JSON (%s)',
                    $envelope['id'],
                    $envelope['type'],
                    json_last_error_msg()
                ));
                continue;
            }
            if (strlen($message) > self::MAX_MESSAGE_BYTES) {
                $failed[] = (string) $envelope['id'];
                error_log(sprintf(
                    '[uengage-platform-sdk] dropping event %s (%s): %d bytes exceeds the SNS '
                        . '256KB message limit',
                    $envelope['id'],
                    $envelope['type'],
                    strlen($message)
                ));
                continue;
            }
            $entries[] = [
                'Id' => $envelope['id'],
                'Message' => $message,
                // Duplicated from the body so SNS filter policies can route
                // without deserialising the message.
                'MessageAttributes' => [
                    'type' => ['DataType' => 'String', 'StringValue' => $envelope['type']],
                    'domain' => ['DataType' => 'String', 'StringValue' => $envelope['domain']],
                    'source' => ['DataType' => 'String', 'StringValue' => $envelope['source']],
                ],
            ];
        }

        if (empty($entries)) {
            return $failed;
        }

        try {
            $result = $client->publishBatch([
                'TopicArn' => $topicArn,
                'PublishBatchRequestEntries' => $entries,
            ]);
        } catch (Throwable $e) {
            throw new EventsException(
                'SNS PublishBatch failed: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $entriesFailed = isset($result['Failed']) ? $result['Failed'] : [];
        foreach ($entriesFailed as $entry) {
            if (isset($entry['Id'])) {
                $failed[] = (string) $entry['Id'];
            }
        }
        return $failed;
    }

    /**
     * JSON-encode one envelope, or null if it cannot be encoded.
     *
     * Tries plain encoding first, then - on PHP 7.2+, where the flag
     * exists - retries with JSON_INVALID_UTF8_SUBSTITUTE so a single bad
     * byte in a customer name degrades to U+FFFD instead of losing the
     * event. The package declares `php: >=7.1`, hence the version guard
     * rather than using the constant unconditionally.
     */
    private static function encodeMessage(array $envelope): ?string
    {
        $message = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        if ($message !== false) {
            return $message;
        }
        if (PHP_VERSION_ID >= 70200 && json_last_error() === JSON_ERROR_UTF8) {
            $message = json_encode(
                $envelope,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($message !== false) {
                return $message;
            }
        }
        return null;
    }

    /**
     * @return object
     */
    private function client()
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if (!self::isAvailable()) {
            throw new EventsException(
                'aws/aws-sdk-php is not installed. Run `composer require aws/aws-sdk-php` in the '
                . 'application that publishes platform events, or pass your own SnsPublisherInterface.'
            );
        }
        $class = self::SNS_CLIENT_CLASS;
        $args = [
            'version' => '2010-03-31',
            'http' => [
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'timeout' => self::TIMEOUT,
            ],
            // One attempt beyond the first. The shutdown handler is not the
            // place to sit in a retry loop.
            'retries' => 1,
        ];
        if ($this->region !== null && $this->region !== '') {
            $args['region'] = $this->region;
        }
        $this->client = new $class($args);
        return $this->client;
    }
}
