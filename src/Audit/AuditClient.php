<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Audit;

use InvalidArgumentException;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Support\Ulid;

/**
 * Buffered audit-event emitter (`POST /v1/audit/events`).
 *
 * PHP's request-scoped execution model means we cannot run a background
 * flush timer like the JS SDK does on Node. The contract here is:
 *
 *   - record() appends to an in-memory queue and returns immediately
 *   - flush() POSTs the queue as a single batch (single retry on 5xx)
 *   - callers SHOULD call flush() before script exit; register_shutdown_function
 *     is set up to attempt a best-effort flush as a safety net
 *
 * The SDK stamps `event_id` (ULID), `occurred_at` (UTC ISO), and
 * `actor.via` (= Config->actorVia) on every event. If `actorVia` is
 * unset at construction, record() throws ConfigException.
 */
class AuditClient
{
    const PATH = '/v1/audit/events';

    /** @var Config */
    private $config;

    /** @var RequestSigner */
    private $signer;

    /** @var array<int, array> */
    private $queue = [];

    /** @var bool */
    private $shutdownRegistered = false;

    /** @var int */
    private $maxBatchSize;

    public function __construct(Config $config, RequestSigner $signer, int $maxBatchSize = 50)
    {
        $this->config = $config;
        $this->signer = $signer;
        $this->maxBatchSize = $maxBatchSize;
    }

    /**
     * Enqueue an event. The caller supplies event_type, tenant, actor
     * (minus `via`), resource, changes, and optional request_id. The
     * SDK stamps event_id, occurred_at, and actor.via.
     *
     * @param array $event
     */
    public function record(array $event): void
    {
        if ($this->config->getActorVia() === null || $this->config->getActorVia() === '') {
            throw new ConfigException(
                'audit.record: actorVia must be configured on the client (Config->actorVia)'
            );
        }
        if (!isset($event['event_type']) || !is_string($event['event_type'])) {
            throw new InvalidArgumentException('audit.record: event_type is required');
        }
        if (!isset($event['actor']) || !is_array($event['actor'])) {
            throw new InvalidArgumentException('audit.record: actor is required');
        }
        $candidate = $event;
        $candidate['event_id'] = self::generateUlid();
        $candidate['occurred_at'] = gmdate('Y-m-d\TH:i:s\Z');
        $candidate['actor'] = array_merge($event['actor'], ['via' => $this->config->getActorVia()]);
        $this->queue[] = $candidate;

        $this->registerShutdownFlush();
        if (count($this->queue) >= $this->maxBatchSize) {
            $this->flush();
        }
    }

    /**
     * POST any queued events as a single batch. No-op if queue is empty.
     * Retries once on 5xx; 4xx + 202 are terminal.
     */
    public function flush(): void
    {
        if (empty($this->queue)) {
            return;
        }
        $events = $this->queue;
        $this->queue = [];

        $body = json_encode(['events' => $events], JSON_UNESCAPED_SLASHES);
        $response = $this->signer->send(
            'POST',
            self::PATH,
            $this->signer->url(self::PATH),
            $body
        );
        if ($response->getStatus() >= 500) {
            // One retry. If still failing, surface the error - callers
            // commonly log + carry on rather than letting an audit
            // failure kill the request.
            $retry = $this->signer->send(
                'POST',
                self::PATH,
                $this->signer->url(self::PATH),
                $body
            );
            if (!$retry->isOk() && $retry->getStatus() !== 202) {
                throw new AuditApiException($retry->getStatus(), $retry->getBody());
            }
            return;
        }
        if (!$response->isOk() && $response->getStatus() !== 202) {
            throw new AuditApiException($response->getStatus(), $response->getBody());
        }
    }

    /**
     * Number of events currently buffered. Useful for tests.
     */
    public function queueLength(): int
    {
        return count($this->queue);
    }

    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }
        $self = $this;
        register_shutdown_function(function () use ($self) {
            try {
                $self->flush();
            } catch (\Throwable $_) {
                // Swallow - shutdown handlers must not throw. Caller
                // should call flush() explicitly to see errors.
            }
        });
        $this->shutdownRegistered = true;
    }

    /**
     * Generate a Crockford-base32 ULID (26 chars). Spec-compliant:
     * 48-bit ms timestamp + 80-bit randomness, lexicographically
     * sortable by time.
     *
     * The implementation moved to `Support\Ulid` when EventsClient
     * needed the same encoder plus a monotonic variant; this stays as
     * the audit-side entry point.
     */
    public static function generateUlid(): string
    {
        return Ulid::generate();
    }
}
