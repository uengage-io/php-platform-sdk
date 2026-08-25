<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Events;

use InvalidArgumentException;
use Throwable;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Support\Ulid;

/**
 * Buffered publisher for the platform event bus (SNS topic
 * `platform-events-{env}`).
 *
 * Same contract as AuditClient, and for the same reason: PHP's
 * request-scoped execution model gives us no background flush timer, and
 * the call sites are inside the legacy order flow.
 *
 *   - publish() builds the envelope, appends it to an in-memory queue,
 *     and returns immediately
 *   - flush() publishes the queue via SNS PublishBatch, <= 10 per call
 *   - register_shutdown_function() flushes at request end as a safety net
 *
 * FAIL-OPEN, unconditionally. flush() catches every Throwable, reports it
 * via error_log(), and returns. A bus outage, an expired instance role,
 * or a missing AWS SDK must never be able to fail an order status change.
 * This is the opposite of the TypeScript `events` namespace, which throws
 * so a platform service can decide for itself - here the decision is made
 * for the caller, because the caller is a checkout request.
 *
 * The SDK stamps `id` (monotonic ULID), `occurredAt`, `version`, `domain`
 * (derived from the type prefix) and `source`. Call sites supply the
 * type, the payload, and the tenant.
 */
class EventsClient
{
    /** SNS caps PublishBatch at 10 entries per call. */
    const BATCH_MAX = 10;

    /** Envelope version stamped on everything published from here. */
    const ENVELOPE_VERSION = 1;

    /** Dotted `resource.operation`, e.g. `order.status_changed`. */
    const EVENT_TYPE_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /**
     * Ceiling on the in-memory queue. A runaway loop publishing events
     * must not turn into unbounded memory growth inside a web request;
     * past this the oldest events are dropped and counted.
     */
    const MAX_QUEUE_SIZE = 500;

    /**
     * Batches the shutdown handler will attempt before dropping the rest.
     * 5 x BATCH_MAX = 50 events, and at most
     * 5 x (CONNECT_TIMEOUT + TIMEOUT) = 15s of worker time against a dead
     * bus. An explicit flush() is uncapped.
     */
    const SHUTDOWN_MAX_CHUNKS = 5;

    /** @var Config */
    private $config;

    /** @var SnsPublisherInterface */
    private $publisher;

    /** @var array<int, array> */
    private $queue = [];

    /** @var bool */
    private $shutdownRegistered = false;

    /** @var int Envelopes this request failed to publish. */
    private $failedCount = 0;

    /** @var int Envelopes dropped because the queue was full. */
    private $droppedCount = 0;

    public function __construct(Config $config, ?SnsPublisherInterface $publisher = null)
    {
        $this->config = $config;
        $this->publisher = $publisher !== null
            ? $publisher
            : new AwsSnsPublisher($config->getEventsRegion());
    }

    /**
     * Buffer one event for publication.
     *
     * @param string $type Dotted `resource.operation`, e.g. `order.status_changed`.
     * @param array  $data Domain payload. Opaque to the bus.
     * @param array  $opts ['tenantId' => string, 'occurredAt' => string, 'source' => string]
     *
     * `occurredAt` is renormalised to UTC `Y-m-d\TH:i:s\Z` rather than passed
     * through: the envelope schema rejects a numeric offset, so `date('c')`
     * would publish and then be dropped by the consumer with no signal here.
     *
     * @return string The envelope id (ULID), so a call site can correlate logs.
     *
     * @throws InvalidArgumentException Malformed type, missing tenantId,
     *         list-shaped data, unmapped domain, or an unparseable occurredAt.
     *         These are programming errors at the call site, not runtime
     *         conditions, so they surface immediately rather than failing open.
     */
    public function publish(string $type, array $data, array $opts = []): string
    {
        if (preg_match(self::EVENT_TYPE_PATTERN, $type) !== 1) {
            throw new InvalidArgumentException(
                'events.publish: type must be a dotted "<resource>.<operation>" name, got ' . $type
            );
        }
        $tenantId = isset($opts['tenantId']) ? (string) $opts['tenantId'] : '';
        if ($tenantId === '') {
            throw new InvalidArgumentException('events.publish: tenantId is required');
        }
        // A list-shaped array would JSON-encode as `[...]`, and every
        // consumer validates `data` as an object. Catch it here rather
        // than in someone's DLQ.
        if (!empty($data) && array_keys($data) === range(0, count($data) - 1)) {
            throw new InvalidArgumentException(
                'events.publish: data must be an associative array (it becomes a JSON object)'
            );
        }

        $domain = self::domainForEventType($type);
        if ($domain === null) {
            throw new InvalidArgumentException(
                'events.publish: cannot route event type "' . $type . '". Its resource prefix '
                . 'must map to a known bus domain (order, customer, menu, offer, loyalty, usage).'
            );
        }

        $envelope = [
            'id' => Ulid::monotonic(),
            'type' => $type,
            'domain' => $domain,
            'source' => isset($opts['source']) && $opts['source'] !== ''
                ? (string) $opts['source']
                : $this->config->getEventSource(),
            'version' => self::ENVELOPE_VERSION,
            'occurredAt' => isset($opts['occurredAt']) && $opts['occurredAt'] !== ''
                ? self::normaliseOccurredAt($opts['occurredAt'])
                : gmdate('Y-m-d\TH:i:s\Z'),
            'tenantId' => $tenantId,
            // An empty PHP array encodes as `[]`; the envelope's `data`
            // must be a JSON object, so force one.
            'data' => empty($data) ? new \stdClass() : $data,
        ];

        if (count($this->queue) >= self::MAX_QUEUE_SIZE) {
            array_shift($this->queue);
            $this->droppedCount++;
        }
        $this->queue[] = $envelope;
        $this->registerShutdownFlush();

        return $envelope['id'];
    }

    /**
     * Publish everything buffered, in chunks of BATCH_MAX.
     *
     * Never throws. Failures are counted (see `failedCount()`) and written
     * to error_log(); the queue is cleared either way, because retrying a
     * status event that has already been superseded is worse than dropping
     * it - the realtime snapshot cache heals from the next transition.
     *
     * Uncapped: an explicit flush() is the caller choosing to wait. The
     * shutdown handler uses the capped path instead - see
     * SHUTDOWN_MAX_CHUNKS.
     */
    public function flush(): void
    {
        $this->flushUpTo(null);
    }

    /**
     * @param int|null $maxChunks Stop after this many PublishBatch calls;
     *                            null for no limit. Anything still pending
     *                            when the cap is hit is dropped and logged.
     */
    private function flushUpTo(?int $maxChunks): void
    {
        if (empty($this->queue)) {
            return;
        }
        $pending = $this->queue;
        $this->queue = [];

        $topicArn = $this->config->getEventsTopicArn();
        if ($topicArn === null || $topicArn === '') {
            $this->failedCount += count($pending);
            $this->logFailure(
                'no topic ARN configured (set PLATFORM_EVENTS_TOPIC_ARN or pass eventsTopicArn)',
                count($pending)
            );
            return;
        }

        $chunks = array_chunk($pending, self::BATCH_MAX);
        $attempted = 0;
        foreach ($chunks as $index => $chunk) {
            if ($maxChunks !== null && $attempted >= $maxChunks) {
                // Drop the tail rather than keep an unreachable bus on the
                // request path. Worst case here is bounded by
                // SHUTDOWN_MAX_CHUNKS x (CONNECT_TIMEOUT + TIMEOUT).
                $remaining = 0;
                foreach (array_slice($chunks, $index) as $rest) {
                    $remaining += count($rest);
                }
                $this->failedCount += $remaining;
                $this->logFailure(
                    'shutdown flush cap reached after ' . $maxChunks
                        . ' batch(es); dropping the remainder',
                    $remaining
                );
                return;
            }
            $attempted++;
            try {
                $failedIds = $this->publisher->publishBatch($topicArn, $chunk);
                if (!empty($failedIds)) {
                    $this->failedCount += count($failedIds);
                    $this->logFailure(
                        'SNS rejected ' . count($failedIds) . ' entr(ies): '
                            . implode(',', $failedIds),
                        count($failedIds)
                    );
                }
            } catch (Throwable $e) {
                $this->failedCount += count($chunk);
                $this->logFailure($e->getMessage(), count($chunk));
            }
        }
    }

    /** Envelopes currently buffered. Useful for tests and coverage checks. */
    public function queueLength(): int
    {
        return count($this->queue);
    }

    /** Envelopes this request failed to publish. */
    public function failedCount(): int
    {
        return $this->failedCount;
    }

    /** Envelopes dropped because the in-memory queue hit its ceiling. */
    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    /**
     * Resource prefix -> bus domain.
     *
     * An explicit map, not a pluralisation rule: the platform's domains
     * are not uniformly plural (`orders` and `customers` are, `menu`,
     * `loyalty` and `usage` are not).
     *
     * Copied from `packages/common/src/events/domains.json`, which is the
     * single source of truth. This package is published to Packagist and
     * cannot reach into the monorepo at runtime, so the copy is asserted
     * against that JSON by tests/Events/DomainMapTest.php - the suite
     * fails the moment the two drift.
     *
     * @return array<string, string>
     */
    public static function domainMap(): array
    {
        return [
            'order' => 'orders',
            'customer' => 'customers',
            'menu' => 'menu',
            'offer' => 'offers',
            'loyalty' => 'loyalty',
            'usage' => 'usage',
        ];
    }

    /**
     * The domain an event type belongs to, or null for an unregistered
     * resource prefix.
     */
    public static function domainForEventType(string $type): ?string
    {
        $map = self::domainMap();
        $resource = substr($type, 0, (int) strpos($type, '.'));
        return isset($map[$resource]) ? $map[$resource] : null;
    }

    /**
     * Renormalise a caller-supplied `occurredAt` to UTC `Y-m-d\TH:i:s\Z`.
     *
     * The envelope schema pins `occurredAt` to a UTC instant with no numeric
     * offset (`z.string().datetime({ offset: false })` in
     * packages/common/src/events/envelope.ts), and it is the only field with a
     * strict wire format. `date('c')` is the reflex in a legacy PHP codebase
     * and yields `2026-08-12T15:45:23+05:30`, which publishes cleanly, passes
     * SNS, passes the subscription filter, and is then dropped by the consumer
     * as `schema_invalid` - a log line in a Lambda nobody is watching, behind a
     * client that fails open. The call site would never learn.
     *
     * So the same instant is converted rather than rejected: an offset-bearing
     * timestamp is not a mistake about *when*, only about *format*. Only a
     * value no date parser can read is a call-site bug, and that throws
     * alongside the `type` / `tenantId` / `data` checks in `publish()`.
     *
     * @param mixed $value
     *
     * @throws InvalidArgumentException When the value is not a parseable date.
     */
    private static function normaliseOccurredAt($value): string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            // A bare epoch is unambiguous; DateTimeImmutable needs the `@`.
            $value = '@' . (string) $value;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(
                'events.publish: occurredAt must be a date string or a Unix timestamp, got '
                . gettype($value)
            );
        }

        try {
            $when = new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            throw new InvalidArgumentException(
                'events.publish: occurredAt "' . $value . '" is not a parseable date. The bus '
                . 'requires a UTC instant; pass gmdate(\'Y-m-d\TH:i:s\Z\') or omit it.'
            );
        }

        return $when->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Flush at request end, off the client's clock and time-bounded.
     *
     * `register_shutdown_function` runs BEFORE PHP-FPM closes the client
     * connection, so without `fastcgi_finish_request()` every millisecond
     * spent talking to SNS is a millisecond the customer waits on a status
     * change that has already been committed. Two guards:
     *
     *   1. `fastcgi_finish_request()` where available (PHP-FPM), which
     *      flushes and closes the response first. The publish then runs on
     *      the worker's own time, invisible to the caller. Absent under
     *      CLI / mod_php / FrankenPHP, hence the function_exists check.
     *   2. A hard cap of SHUTDOWN_MAX_CHUNKS batches even so, because
     *      guard 1 frees the client but still holds an FPM worker. An
     *      unreachable bus with a full queue would otherwise pin a worker
     *      for MAX_QUEUE_SIZE / BATCH_MAX x (connect + read) timeouts, and
     *      workers are the scarce resource under load.
     *
     * Events past the cap are counted and logged, not retried. This is the
     * fail-open contract: the bus is best-effort, and the realtime
     * snapshot cache heals from the next transition.
     */
    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $self = $this;
        register_shutdown_function(function () use ($self) {
            try {
                $self->finishRequestBeforeFlush();
                $self->flushOnShutdown();
            } catch (Throwable $_) {
                // flush already swallows everything; this is belt and
                // braces, because a throwing shutdown handler would take
                // the response down with it.
            }
        });
        $this->shutdownRegistered = true;
    }

    /**
     * Close the client connection before flushing, where the SAPI allows
     * it. Public only so the shutdown closure can call it; not part of the
     * supported surface.
     *
     * @internal
     */
    public function finishRequestBeforeFlush(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }

    /**
     * Capped flush used by the shutdown handler.
     *
     * @internal
     */
    public function flushOnShutdown(): void
    {
        $this->flushUpTo(self::SHUTDOWN_MAX_CHUNKS);
    }

    private function logFailure(string $reason, int $count): void
    {
        error_log(sprintf(
            '[uengage-platform-sdk] events publish failed for %d event(s): %s',
            $count,
            $reason
        ));
    }
}
