<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Alerts;

use InvalidArgumentException;
use Throwable;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\HttpResponse;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Support\Ulid;

/**
 * Client for the platform Alerts service.
 *
 * Mirrors the JS SDK's `platform.alerts` namespace. Two halves with two
 * different transports:
 *
 *   raise()       sends the alert message straight to the alerts SQS FIFO
 *                 queue (`alerts-{env}.fifo`) through {@see SqsSenderInterface}.
 *                 Uses the host's AWS credentials (sqs:SendMessage, granted
 *                 by the queue's resource policy to the platform's producer
 *                 roles), NOT the SDK auth modes. No SNS, no event bus.
 *   list() / get() / setStatus()
 *                 call the alerts HTTP API (`/v1/alerts`) with the SDK's
 *                 Bearer token. The token needs `alerts:read` for the reads
 *                 and `alerts:write` for setStatus(); the SDK does not check
 *                 that, the server answers 403 and it surfaces as
 *                 {@see AlertsApiException}.
 *
 * The contract lives in `packages/platform-sdk/src/alerts/schema.ts`; the
 * type registry, field limits, queue URLs and message shape below are a
 * copy of it and must move with it.
 *
 * raise() DELIVERY SEMANTICS — the same contract as EventsClient:
 *
 *   - Invalid input throws InvalidArgumentException immediately. A bad
 *     type, an over-long title or a nested context value is a bug at the
 *     call site, not a runtime condition.
 *   - Valid input is BUFFERED in this client's own queue and sent at
 *     request shutdown (or on an explicit `$platform->alerts->flush()`).
 *     raise() returning does not mean the alert reached the queue.
 *   - Sending FAILS OPEN: an SQS outage, no resolvable queue URL (see
 *     queueUrl()), a missing
 *     AWS SDK or a denied sqs:SendMessage is logged via error_log() and
 *     counted in `$platform->alerts->failedCount()`, never thrown.
 *     Long-running workers (CLI, queue consumers) should call
 *     `$platform->alerts->flush()` themselves rather than rely on the
 *     shutdown handler.
 *
 * Matching happens on the service: while an alert with the same
 * (businessId, parentId, type) is open or muted, a new message adds to its
 * `count` and the latest title / description / context / notify win. Once
 * it is resolved, the next raise() starts a new alert row.
 */
class AlertsClient
{
    /**
     * Alert type -> category. Copy of ALERT_TYPES in the TS schema. Category
     * is fixed per type and never sent by callers, so an alert cannot be
     * filed under the wrong audience.
     */
    const ALERT_TYPES = [
        'wallet.low_balance' => 'business',
        'wallet.credit_line_exhausted' => 'business',
        'pg.invalid_credentials' => 'business',
    ];

    const ALERT_CATEGORIES = ['business', 'engineering'];

    const ALERT_STATUSES = ['open', 'resolved', 'muted'];

    /**
     * Default alerts queue per platform environment. Copy of
     * ALERT_QUEUE_URLS in the TS schema. Picked by the `env` option /
     * UENGAGE_ENV; the `alertsQueueUrl` option / PLATFORM_ALERTS_QUEUE_URL
     * env var override it.
     */
    const ALERT_QUEUE_URLS = [
        'uat' => 'https://sqs.ap-south-1.amazonaws.com/571896969292/alerts-uat.fifo',
        'prod' => 'https://sqs.ap-south-1.amazonaws.com/822426641710/alerts-prod.fifo',
    ];

    /** 409 error code from setStatus() when reopening is blocked by a newer alert. */
    const ERROR_NEWER_ALERT_OPEN = 'newer_alert_open';

    const TITLE_MAX = 200;
    const DESCRIPTION_MAX = 4000;
    const TENANT_ID_MAX = 48;
    /** list() `userId` filter: a dashboard user id. */
    const USER_ID_MAX = 64;
    const CONTEXT_MAX_KEYS = 50;
    const CONTEXT_KEY_MAX = 64;
    const CONTEXT_STRING_MAX = 1024;

    /** `GET /v1/alerts` page size bounds (server default is 25). */
    const LIST_LIMIT_MAX = 100;

    /** SQS caps SendMessageBatch at 10 entries per call. */
    const BATCH_MAX = 10;

    /**
     * Ceiling on the in-memory queue; past it the oldest alerts are dropped
     * and counted. Same reasoning as EventsClient::MAX_QUEUE_SIZE.
     */
    const MAX_QUEUE_SIZE = 500;

    /**
     * Batches the shutdown handler attempts before dropping the rest
     * (5 x 10 = 50 alerts). An explicit flush() is uncapped.
     */
    const SHUTDOWN_MAX_CHUNKS = 5;

    /** @var Config */
    private $config;

    /** @var RequestSigner */
    private $signer;

    /** @var SqsSenderInterface */
    private $sender;

    /** @var array<int, array> */
    private $queue = [];

    /** @var bool */
    private $shutdownRegistered = false;

    /** @var int Alerts this client failed to send. */
    private $failedCount = 0;

    /** @var int Alerts dropped because the queue was full. */
    private $droppedCount = 0;

    public function __construct(Config $config, RequestSigner $signer, ?SqsSenderInterface $sender = null)
    {
        $this->config = $config;
        $this->signer = $signer;
        $this->sender = $sender !== null ? $sender : new AwsSqsSender();
    }

    // ─── Raise (SQS FIFO) ────────────────────────────────────────────────

    /**
     * Validate an alert, stamp its `id` and `occurredAt`, and queue it for
     * the alerts FIFO queue.
     *
     * See the class docblock for delivery semantics: buffered, flushed at
     * shutdown or on flush(), fail-open.
     *
     * @param array $alert {
     *     title: string (1-200 after trim),
     *     description: string (1-4000 after trim),
     *     tenant: {parentId: int|string, businessId: int|string},
     *     type: one of the ALERT_TYPES keys,
     *     notify?: bool (default false),
     *     context?: array<string, string|int|float|bool> (flat, <= 50 keys)
     * }
     *
     * @return string The message id (ULID), for log correlation. It is also
     *                the FIFO deduplication id. It is NOT the alert id: the
     *                service decides which alert row the message lands on.
     *
     * @throws InvalidArgumentException When the alert does not satisfy the contract.
     */
    public function raise(array $alert): string
    {
        $message = self::buildMessage($alert, gmdate('Y-m-d\TH:i:s\Z'), Ulid::monotonic());

        if (count($this->queue) >= self::MAX_QUEUE_SIZE) {
            array_shift($this->queue);
            $this->droppedCount++;
        }
        $this->queue[] = $message;
        $this->registerShutdownFlush();

        return $message['id'];
    }

    /**
     * Validate caller input and build the queue message: the normalised
     * input plus the derived `category` and the stamped `id` and
     * `occurredAt`. Unknown keys are dropped, as the TS schema's `z.object()`
     * strips them — including any `category`, `id` or `occurredAt` the
     * caller tried to supply.
     *
     * Public so call sites and tests can validate without sending.
     *
     * @param string|null $id ULID to stamp; a fresh one when null.
     *
     * @throws InvalidArgumentException
     */
    public static function buildMessage(array $alert, string $occurredAt, ?string $id = null): array
    {
        $type = isset($alert['type']) ? $alert['type'] : null;
        if (!is_string($type) || !array_key_exists($type, self::ALERT_TYPES)) {
            throw new InvalidArgumentException(sprintf(
                'alerts.raise: type must be one of %s (got %s)',
                implode(', ', array_keys(self::ALERT_TYPES)),
                self::describe($type)
            ));
        }

        $tenant = isset($alert['tenant']) ? $alert['tenant'] : null;
        if (!is_array($tenant)) {
            throw new InvalidArgumentException(
                'alerts.raise: tenant must be an array {parentId, businessId}'
            );
        }

        $notify = array_key_exists('notify', $alert) ? $alert['notify'] : false;
        if (!is_bool($notify)) {
            throw new InvalidArgumentException(sprintf(
                'alerts.raise: notify must be a boolean (got %s)',
                self::describe($notify)
            ));
        }

        $context = array_key_exists('context', $alert) ? $alert['context'] : [];

        return [
            'title' => self::boundedString('raise.title', isset($alert['title']) ? $alert['title'] : null, self::TITLE_MAX),
            'description' => self::boundedString(
                'raise.description',
                isset($alert['description']) ? $alert['description'] : null,
                self::DESCRIPTION_MAX
            ),
            'tenant' => [
                'parentId' => self::tenantId('raise.tenant.parentId', isset($tenant['parentId']) ? $tenant['parentId'] : null),
                'businessId' => self::tenantId('raise.tenant.businessId', isset($tenant['businessId']) ? $tenant['businessId'] : null),
            ],
            'notify' => $notify,
            'type' => $type,
            'context' => self::context($context),
            'id' => $id !== null ? $id : Ulid::monotonic(),
            'category' => self::categoryForType($type),
            'occurredAt' => $occurredAt,
        ];
    }

    /**
     * FIFO MessageGroupId for a message: `${parentId}#${businessId}#${type}`.
     * One group per alert identity, so the consumer never handles two
     * messages for the same alert at once. Copy of `alertMessageGroupId`.
     *
     * @param array $message A built message (or anything with tenant + type).
     */
    public static function messageGroupId(array $message): string
    {
        return $message['tenant']['parentId'] . '#' . $message['tenant']['businessId'] . '#' . $message['type'];
    }

    /**
     * The category an alert type files under.
     *
     * @throws InvalidArgumentException For an unregistered type.
     */
    public static function categoryForType(string $type): string
    {
        if (!array_key_exists($type, self::ALERT_TYPES)) {
            throw new InvalidArgumentException('alerts: unknown alert type "' . $type . '"');
        }
        return self::ALERT_TYPES[$type];
    }

    /**
     * The queue raise() sends to, or null when none can be resolved.
     * Same order as the TS SDK's resolveAlertsQueueUrl():
     *
     *   1. the `alertsQueueUrl` option, else PLATFORM_ALERTS_QUEUE_URL
     *   2. ALERT_QUEUE_URLS[the `env` option, else UENGAGE_ENV]
     *
     * An unknown env (e.g. `dev`) resolves to null, which flush() reports
     * via error_log() - the TS SDK throws there; this client fails open.
     */
    public function queueUrl(): ?string
    {
        $explicit = $this->config->getAlertsQueueUrl();
        if ($explicit !== null) {
            return $explicit;
        }
        $env = $this->config->getEnv();
        $defaults = self::ALERT_QUEUE_URLS;
        return $env !== null && isset($defaults[$env]) ? $defaults[$env] : null;
    }

    /**
     * Send everything buffered, in SendMessageBatch calls of up to 10.
     *
     * Never throws. Failures are counted (see failedCount()) and written to
     * error_log(); the queue is cleared either way. Uncapped: an explicit
     * flush() is the caller choosing to wait.
     */
    public function flush(): void
    {
        $this->flushUpTo(null);
    }

    /** Alerts currently buffered. */
    public function queueLength(): int
    {
        return count($this->queue);
    }

    /** Alerts this client failed to send (cumulative for its lifetime). */
    public function failedCount(): int
    {
        return $this->failedCount;
    }

    /** Alerts dropped because the in-memory queue hit its ceiling. */
    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    /**
     * @param int|null $maxChunks Stop after this many batches; null for no limit.
     */
    private function flushUpTo(?int $maxChunks): void
    {
        if (empty($this->queue)) {
            return;
        }
        $pending = $this->queue;
        $this->queue = [];

        $queueUrl = $this->queueUrl();
        if ($queueUrl === null) {
            $this->failedCount += count($pending);
            $env = $this->config->getEnv();
            $this->logFailure(
                ($env === null ? 'no alerts queue configured' : 'no default alerts queue for env "' . $env . '"')
                    . '. Set PLATFORM_ALERTS_QUEUE_URL (or pass alertsQueueUrl), or set UENGAGE_ENV '
                    . '(or pass env) to uat or prod',
                count($pending)
            );
            return;
        }

        $chunks = array_chunk($pending, self::BATCH_MAX);
        $attempted = 0;
        foreach ($chunks as $index => $chunk) {
            if ($maxChunks !== null && $attempted >= $maxChunks) {
                $remaining = 0;
                foreach (array_slice($chunks, $index) as $rest) {
                    $remaining += count($rest);
                }
                $this->failedCount += $remaining;
                $this->logFailure(
                    'shutdown flush cap reached after ' . $maxChunks . ' batch(es); dropping the remainder',
                    $remaining
                );
                return;
            }
            $attempted++;
            try {
                $failedIds = $this->sender->sendBatch($queueUrl, $chunk);
                if (!empty($failedIds)) {
                    $this->failedCount += count($failedIds);
                    $this->logFailure(
                        'SQS rejected ' . count($failedIds) . ' entr(ies): ' . implode(',', $failedIds),
                        count($failedIds)
                    );
                }
            } catch (Throwable $e) {
                $this->failedCount += count($chunk);
                $this->logFailure($e->getMessage(), count($chunk));
            }
        }
    }

    /**
     * Flush at request end, off the client's clock and time-bounded. Same
     * guards as EventsClient: fastcgi_finish_request() first where the SAPI
     * has it, then at most SHUTDOWN_MAX_CHUNKS batches.
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
                // flush already swallows everything; a throwing shutdown
                // handler would take the response down with it.
            }
        });
        $this->shutdownRegistered = true;
    }

    /**
     * @internal Public only so the shutdown closure can call it.
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
            '[uengage-platform-sdk] alerts raise failed for %d alert(s): %s',
            $count,
            $reason
        ));
    }

    // ─── Read / manage (HTTP API) ────────────────────────────────────────

    /**
     * GET /v1/alerts — one page of alerts, most recent `lastSeenAt` first.
     *
     * At least one of businessId, parentId, type, category, status, userId
     * or `notify => true` is required (the server has no unfiltered scan).
     * `notify => true` keeps only alerts whose latest message had notify on;
     * `notify => false` is the same as leaving it out. `userId` keeps only
     * alerts that dashboard user can see (their id is in the alert's
     * `userIds`); it counts as a filter on its own and is trusted like
     * setStatus()'s `actor` — the calling backend decides which user it is
     * acting for. A `userId` page may come back short (fewer than `limit`
     * items with a non-null `nextCursor`). Page with the returned
     * `nextCursor` until it is null. `businessId` + `type` lists an alert's
     * history: every row for that identity, current one and resolved ones.
     *
     * Each item is an Alert (see get()).
     *
     * @param array $query {businessId?, parentId?, type?, category?,
     *                      status?: open|resolved|muted, notify?: bool,
     *                      userId?: string|int (1-64 chars),
     *                      limit?: 1-100, cursor?: string}
     * @return array{items: array, nextCursor: string|null}
     *
     * @throws InvalidArgumentException On a malformed query.
     * @throws AlertsApiException On a non-2xx response or a broken body.
     */
    public function list(array $query): array
    {
        $params = [];
        foreach (['businessId', 'parentId'] as $key) {
            if (isset($query[$key])) {
                $params[$key] = self::tenantId('list.' . $key, $query[$key]);
            }
        }
        if (isset($query['type'])) {
            if (!is_string($query['type']) || !array_key_exists($query['type'], self::ALERT_TYPES)) {
                throw new InvalidArgumentException(sprintf(
                    'alerts.list: type must be one of %s (got %s)',
                    implode(', ', array_keys(self::ALERT_TYPES)),
                    self::describe($query['type'])
                ));
            }
            $params['type'] = $query['type'];
        }
        if (isset($query['category'])) {
            $params['category'] = self::oneOf('list.category', $query['category'], self::ALERT_CATEGORIES);
        }
        if (isset($query['status'])) {
            $params['status'] = self::oneOf('list.status', $query['status'], self::ALERT_STATUSES);
        }
        if (isset($query['notify'])) {
            if (!is_bool($query['notify'])) {
                throw new InvalidArgumentException(sprintf(
                    'alerts.list: notify must be a boolean (got %s)',
                    self::describe($query['notify'])
                ));
            }
            // Only `true` filters (a sparse index on the service). `false`
            // means "don't filter", so it is not sent and does not count
            // towards the at-least-one-filter rule.
            if ($query['notify']) {
                $params['notify'] = 'true';
            }
        }
        if (isset($query['userId'])) {
            $params['userId'] = self::userId('list.userId', $query['userId']);
        }
        if (empty($params)) {
            throw new InvalidArgumentException(
                'alerts.list: pass at least one of businessId, parentId, type, category, status, userId or notify => true'
            );
        }
        if (isset($query['limit'])) {
            $limit = $query['limit'];
            if (
                is_bool($limit) || !is_numeric($limit) || (int) $limit != $limit
                || (int) $limit < 1 || (int) $limit > self::LIST_LIMIT_MAX
            ) {
                throw new InvalidArgumentException(sprintf(
                    'alerts.list: limit must be an integer between 1 and %d (got %s)',
                    self::LIST_LIMIT_MAX,
                    self::describe($limit)
                ));
            }
            $params['limit'] = (int) $limit;
        }
        if (isset($query['cursor'])) {
            if (!is_string($query['cursor']) || $query['cursor'] === '') {
                throw new InvalidArgumentException('alerts.list: cursor must be a non-empty string');
            }
            $params['cursor'] = $query['cursor'];
        }

        $path = '/v1/alerts';
        $response = $this->signer->send('GET', $path, $this->signer->url($path, '?' . http_build_query($params)));
        $body = $this->expectJson($response, 'list');

        if (!isset($body['items']) || !is_array($body['items'])) {
            throw new AlertsApiException(
                $response->getStatus(),
                'alerts.list: response is missing the `items` array: ' . $response->getBody()
            );
        }
        $next = isset($body['nextCursor']) ? $body['nextCursor'] : null;
        if ($next !== null && !is_string($next)) {
            throw new AlertsApiException(
                $response->getStatus(),
                'alerts.list: response `nextCursor` is not a string or null: ' . $response->getBody()
            );
        }

        return [
            'items' => array_values($body['items']),
            'nextCursor' => ($next === '' ? null : $next),
        ];
    }

    /**
     * GET /v1/alerts/{id} — one alert. `id` is the opaque id from list()
     * (`${businessId}.${type}.${ulid}`, stable for the row's life).
     *
     * Alert shape: {
     *     id, parentId, businessId: string,
     *     title, description: string, type, category, status: string,
     *     context: array<string, scalar>, notify: bool,
     *     count: int            messages received while open or muted,
     *     firstSeenAt: string   occurredAt of the message that started it,
     *     lastSeenAt: string    occurredAt of the latest counted message,
     *     updatedAt: string     last change of any kind (message or status),
     *     resolvedAt?, resolvedBy?, resolutionComment?,
     *     mutedAt?, mutedBy?, muteComment?,
     *     userIds: string[]     dashboard users who can see it, decided
     *                           once when the row starts (the service's
     *                           audience rules: business+type, else
     *                           parent+type, else a default list); never
     *                           changes afterwards,
     *     reopenedAt?, reopenedBy?, reopenComment?: string
     * }
     *
     * @return array Alert
     * @throws AlertsApiException 404 when the alert does not exist.
     */
    public function get(string $id): array
    {
        $path = '/v1/alerts/' . rawurlencode(self::alertId('get', $id));
        $response = $this->signer->send('GET', $path, $this->signer->url($path));

        return $this->expectJson($response, 'get');
    }

    /**
     * POST /v1/alerts/{id}/status — resolve, mute, unmute or reopen an alert.
     *
     * `comment` is required when moving to `resolved` or `muted`, optional
     * otherwise (a reopen stores it as `reopenComment`). `actor` identifies
     * the dashboard user making the change; the calling service is trusted
     * to pass it. It may be omitted only when the client authenticates with
     * an alerts user token (see AuthClient::mintAlertToken), whose user the
     * server records instead; a service token without it gets a 400.
     * Requires `alerts:write`.
     *
     * Transitions: open <-> muted and open|muted -> resolved always work.
     * resolved -> open (reopen) works only while no other open or muted
     * alert exists for the same (businessId, parentId, type) - a later
     * raise() started a new row after this one was resolved. The server then
     * answers 409 `newer_alert_open`, thrown as AlertsApiException; check
     * `$e->getErrorCode() === AlertsClient::ERROR_NEWER_ALERT_OPEN`.
     *
     * @param array $input {status: open|resolved|muted, actor?: string, comment?: string}
     * @return array The updated Alert.
     * @throws AlertsApiException On a non-2xx response, including that 409.
     */
    public function setStatus(string $id, array $input): array
    {
        $id = self::alertId('setStatus', $id);
        $status = self::oneOf('setStatus.status', isset($input['status']) ? $input['status'] : null, self::ALERT_STATUSES);

        $body = ['status' => $status];
        $actor = isset($input['actor']) ? $input['actor'] : null;
        if ($actor !== null) {
            if (!is_string($actor) || trim($actor) === '') {
                throw new InvalidArgumentException('alerts.setStatus: actor must be a non-empty string');
            }
            $body['actor'] = $actor;
        }
        $comment = isset($input['comment']) ? $input['comment'] : null;
        if ($comment !== null) {
            if (!is_string($comment)) {
                throw new InvalidArgumentException(sprintf(
                    'alerts.setStatus: comment must be a string (got %s)',
                    self::describe($comment)
                ));
            }
            $body['comment'] = $comment;
        }
        if (($status === 'resolved' || $status === 'muted') && ($comment === null || trim($comment) === '')) {
            throw new InvalidArgumentException(
                'alerts.setStatus: comment is required when status is ' . $status
            );
        }

        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false && PHP_VERSION_ID >= 70200 && json_last_error() === JSON_ERROR_UTF8) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        if ($json === false) {
            throw new InvalidArgumentException(
                'alerts.setStatus: payload is not encodable as JSON (' . json_last_error_msg() . ')'
            );
        }

        $path = '/v1/alerts/' . rawurlencode($id) . '/status';
        $response = $this->signer->send('POST', $path, $this->signer->url($path), $json);

        return $this->expectJson($response, 'setStatus');
    }

    // ─── Validation ──────────────────────────────────────────────────────

    /**
     * Trimmed, non-empty, at most $max characters.
     *
     * @param mixed $value
     */
    private static function boundedString(string $label, $value, int $max): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf(
                'alerts.%s: expected a string (got %s)',
                $label,
                self::describe($value)
            ));
        }
        $value = self::trim($value);
        $length = self::length($value);
        if ($length < 1 || $length > $max) {
            throw new InvalidArgumentException(sprintf(
                'alerts.%s: must be 1-%d characters after trimming (got %d)',
                $label,
                $max,
                $length
            ));
        }
        return $value;
    }

    /**
     * Tenant ids travel as strings. Integers (what the legacy stack holds)
     * are accepted and stringified, so 7175 and "7175" match the same
     * alert on the service side.
     *
     * @param mixed $value
     */
    private static function tenantId(string $label, $value): string
    {
        // Floats only when whole and within the JS safe-integer range, as the
        // TS schema allows: 1e60 would become a 61-digit id.
        if (is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value
            && abs($value) <= 9007199254740991)) {
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'alerts.%s: must be a positive integer or a non-empty string (got %s)',
                    $label,
                    self::describe($value)
                ));
            }
            $id = is_int($value) ? (string) $value : sprintf('%.0f', $value);
            if (preg_match('/^[A-Za-z0-9_-]{1,' . self::TENANT_ID_MAX . '}$/', $id) === 1) {
                return $id;
            }
        }
        if (is_string($value)) {
            // Letters, digits, _ and - only: a dot would split the public
            // alert id, and the FIFO group id must stay within 128 chars.
            $trimmed = self::trim($value);
            if (preg_match('/^[A-Za-z0-9_-]{1,' . self::TENANT_ID_MAX . '}$/', $trimmed) === 1) {
                return $trimmed;
            }
        }
        throw new InvalidArgumentException(sprintf(
            'alerts.%s: must be a positive integer or a 1-%d character string of letters, digits, _ and - (got %s)',
            $label,
            self::TENANT_ID_MAX,
            self::describe($value)
        ));
    }

    /**
     * Dashboard user id: an int (stringified) or a 1-USER_ID_MAX character
     * string (trimmed).
     *
     * @param mixed $value
     */
    private static function userId(string $label, $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            $trimmed = self::trim($value);
            $length = self::length($trimmed);
            if ($length >= 1 && $length <= self::USER_ID_MAX) {
                return $trimmed;
            }
        }
        throw new InvalidArgumentException(sprintf(
            'alerts.%s: must be an integer or a 1-%d character string (got %s)',
            $label,
            self::USER_ID_MAX,
            self::describe($value)
        ));
    }

    /**
     * Flat string-keyed map of scalars. Returned as stdClass when empty so
     * it encodes as `{}`, never `[]`.
     *
     * @param mixed $context
     * @return array|\stdClass
     */
    private static function context($context)
    {
        if (!is_array($context)) {
            throw new InvalidArgumentException(
                'alerts.raise: context must be an associative array of scalars'
            );
        }
        if (empty($context)) {
            return new \stdClass();
        }
        // A list would encode as a JSON array; the contract is a record.
        if (array_keys($context) === range(0, count($context) - 1)) {
            throw new InvalidArgumentException(
                'alerts.raise: context must be an associative array (it becomes a JSON object), not a list'
            );
        }
        if (count($context) > self::CONTEXT_MAX_KEYS) {
            throw new InvalidArgumentException(sprintf(
                'alerts.raise: context may hold at most %d keys (got %d)',
                self::CONTEXT_MAX_KEYS,
                count($context)
            ));
        }
        foreach ($context as $key => $value) {
            $keyLength = self::length((string) $key);
            if ($keyLength < 1 || $keyLength > self::CONTEXT_KEY_MAX) {
                throw new InvalidArgumentException(sprintf(
                    'alerts.raise: context keys must be 1-%d characters (got "%s")',
                    self::CONTEXT_KEY_MAX,
                    (string) $key
                ));
            }
            if (is_string($value)) {
                if (self::length($value) > self::CONTEXT_STRING_MAX) {
                    throw new InvalidArgumentException(sprintf(
                        'alerts.raise: context.%s is longer than %d characters',
                        (string) $key,
                        self::CONTEXT_STRING_MAX
                    ));
                }
            } elseif (is_float($value)) {
                if (!is_finite($value)) {
                    throw new InvalidArgumentException(sprintf(
                        'alerts.raise: context.%s must be a finite number',
                        (string) $key
                    ));
                }
            } elseif (!is_int($value) && !is_bool($value)) {
                throw new InvalidArgumentException(sprintf(
                    'alerts.raise: context.%s must be a string, number or boolean - context is flat, '
                        . 'no nesting (got %s)',
                    (string) $key,
                    gettype($value)
                ));
            }
        }
        return $context;
    }

    /**
     * @param mixed $value
     */
    private static function oneOf(string $label, $value, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf(
                'alerts.%s: must be one of %s (got %s)',
                $label,
                implode(', ', $allowed),
                self::describe($value)
            ));
        }
        return $value;
    }

    private static function alertId(string $label, string $id): string
    {
        if (trim($id) === '') {
            throw new InvalidArgumentException('alerts.' . $label . ': id must be a non-empty string');
        }
        return $id;
    }

    /**
     * Length in UTF-16 code units, as the consumer's zod schema counts it:
     * a code point above U+FFFF (most emoji) counts 2. Needs no mbstring.
     * Invalid UTF-8 falls back to bytes (stricter, never looser).
     */
    private static function length(string $value): int
    {
        $points = preg_match_all('/./su', $value);
        $astral = preg_match_all('/[\x{10000}-\x{10FFFF}]/u', $value);
        if ($points === false || $astral === false) {
            return strlen($value);
        }
        return $points + $astral;
    }

    /**
     * Trim like JS `String.prototype.trim`, which the consumer's zod schema
     * uses: ASCII whitespace plus Unicode spaces, line/paragraph separators
     * and the BOM. Invalid UTF-8 falls back to PHP's trim().
     */
    private static function trim(string $value): string
    {
        $ws = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
        $trimmed = preg_replace('/^' . $ws . '+|' . $ws . '+$/u', '', $value);
        return $trimmed === null ? trim($value) : $trimmed;
    }

    /**
     * @param mixed $value
     */
    private static function describe($value): string
    {
        return is_scalar($value) || $value === null ? var_export($value, true) : gettype($value);
    }

    // ─── Responses ───────────────────────────────────────────────────────

    private function expectJson(HttpResponse $response, string $label): array
    {
        if (!$response->isOk()) {
            throw new AlertsApiException($response->getStatus(), $response->getBody());
        }
        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new AlertsApiException(
                $response->getStatus(),
                sprintf('alerts.%s: response was not valid JSON: %s', $label, $response->getBody())
            );
        }
        return $decoded;
    }
}
