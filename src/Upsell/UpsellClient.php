<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Upsell;

use InvalidArgumentException;
use Throwable;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\HttpResponse;
use Uengage\PlatformSdk\Http\RequestSigner;

/**
 * Client for the platform upsell API (`/v1/upsell/*`).
 *
 * Mirrors the JS SDK's `platform.upsell` namespace. Three calls carry a
 * channel integration:
 *
 *   placements()   which slots this channel + tenant should render
 *   suggest()      the items for one slot, given the live cart
 *   reportEvents() shown / added / ignored, feeding attach rate
 *
 * plus three admin methods for placement configuration.
 *
 * Reads (`placements`, `suggest`, `reportEvents`) accept a service OR a
 * user JWT — a storefront calls them with the customer's session token.
 * The `admin*` methods require a service-actor token carrying
 * `upsell.placements:read` / `:write`; the SDK does not enforce that, the
 * server answers 403 and we surface it as {@see UpsellApiException}.
 *
 * Two conventions worth knowing before reading the methods:
 *
 *   - ids are COERCED, not merely checked. Legacy callers hold ids as
 *     strings out of `$_GET`, `$_SESSION` and mysqli, and the server
 *     coerces too (`z.coerce.number()`), so a client that rejected '5'
 *     would be stricter than the API it wraps.
 *   - list payloads are re-indexed before encoding. `json_encode` emits a
 *     JSON object for any array whose keys are not sequential from 0, and
 *     an `array_filter` over events or cart lines produces exactly that.
 */
class UpsellClient
{
    /** Server-side caps, mirrored so an over-long batch fails here with a clear message. */
    const MAX_EVENTS = 50;
    const MAX_CART_ITEMS = 200;

    /** @var Config */
    private $config;

    /** @var RequestSigner */
    private $signer;

    public function __construct(Config $config, RequestSigner $signer)
    {
        $this->config = $config;
        $this->signer = $signer;
    }

    // ─── Channel reads ───────────────────────────────────────────────────

    /**
     * GET /v1/upsell/placements — the resolved placements for a channel and
     * tenant, with business overriding brand overriding platform default.
     *
     * A tenant with no configuration of its own still returns rows: the
     * platform defaults answer. An empty array means this channel has no
     * placements at all.
     *
     * @param array $query {channel: string, parentId: int|string, businessId: int|string}
     * @return array list of Placement {key, channel, touchpoint, enabled, display, configVersion}
     */
    public function placements(array $query): array
    {
        $this->assertChannel('placements', $query);
        $parentId = $this->coercePositiveInt('placements.parentId', $query['parentId'] ?? null);
        $businessId = $this->coercePositiveInt('placements.businessId', $query['businessId'] ?? null);

        $params = [
            'channel' => $query['channel'],
            'parentId' => $parentId,
            'businessId' => $businessId,
        ];
        $path = '/v1/upsell/placements';
        $response = $this->signer->send('GET', $path, $this->signer->url($path, '?' . http_build_query($params)));

        return $this->expectList($response, 'placements');
    }

    /**
     * POST /v1/upsell/suggestions — the items for one placement.
     *
     * Always answers 200 with a (possibly empty) list unless the placement
     * itself is unknown, which is a 404. An empty list is a normal outcome:
     * the price band is a percentage of the cart subtotal, so a small cart
     * legitimately has nothing to suggest. Render nothing, not an error.
     *
     * Send `sectionName` and `veg` on every cart line. Both are optional in
     * the schema and load-bearing in the engine: `veg` drives the dietary
     * rule and only engages when EVERY line carries it, and `sectionName`
     * drives the no-repeat-a-cart-category rule. Omitting them silently
     * disables both protections.
     *
     * @param array $input {channel, placement, tenant: {parentId, businessId},
     *                      context: {cart: {items, subtotal}, fulfilmentMode?,
     *                                customer?: {id}, sessionId?}}
     * @return array SuggestionsResult {placement, touchpoint, items, meta}
     */
    public function suggest(array $input): array
    {
        $this->assertChannel('suggest', $input);
        $this->assertNonEmptyString('suggest.placement', $input['placement'] ?? null);
        $input['tenant'] = $this->normaliseTenant('suggest', $input['tenant'] ?? null);
        $input['context'] = $this->normaliseContext('suggest', $input['context'] ?? null);

        $path = '/v1/upsell/suggestions';
        $body = $this->encode('suggest', $input);
        $response = $this->signer->send('POST', $path, $this->signer->url($path), $body);

        return $this->expectJson($response, 'suggest');
    }

    /**
     * POST /v1/upsell/events — attach-rate telemetry for a rendered placement.
     *
     * FAILS OPEN. A timeout, a DNS failure, an expired token, a 500, an
     * unencodable payload — none of them throw. They are logged via
     * `error_log` and the method returns `['accepted' => 0]`, so a cold
     * upsell Lambda cannot take down a cart render. This matches
     * `EventsClient`'s posture in this package, and it is why the README
     * shows this call without a try/catch.
     *
     * Programmer errors still throw: a missing tenant or an empty batch is
     * a bug in the calling code, surfaced immediately in development rather
     * than swallowed into a metric nobody reads.
     *
     * Echo `meta.configVersion` from the suggestions response so an attach
     * can be attributed to the configuration that produced it. The dataset
     * this builds is what the signal weights get tuned from — a channel
     * that skips it stays on default weights indefinitely.
     *
     * @param array $input {channel, placement, tenant, sessionId?, configVersion?,
     *                      events: [{type: shown|added|ignored, itemId, reasonCode?, occurredAt?}]}
     * @return array {accepted: int} — `['accepted' => 0]` on any failure
     */
    public function reportEvents(array $input): array
    {
        $this->assertChannel('reportEvents', $input);
        $this->assertNonEmptyString('reportEvents.placement', $input['placement'] ?? null);
        $input['tenant'] = $this->normaliseTenant('reportEvents', $input['tenant'] ?? null);

        $events = $input['events'] ?? null;
        if (!is_array($events) || count($events) === 0) {
            throw new InvalidArgumentException('upsell.reportEvents: events must be a non-empty array');
        }
        if (count($events) > self::MAX_EVENTS) {
            throw new InvalidArgumentException(sprintf(
                'upsell.reportEvents: at most %d events per call (got %d) — the server rejects the '
                    . 'whole batch above that, and this call fails open, so it would be lost silently',
                self::MAX_EVENTS,
                count($events)
            ));
        }
        // Re-index: array_filter (the obvious way to send only `added`)
        // leaves gaps, and json_encode turns a gapped array into a JSON
        // object, which the server's z.array() rejects with a 400.
        $input['events'] = array_values($events);

        $path = '/v1/upsell/events';
        try {
            $body = $this->encode('reportEvents', $input);
            $response = $this->signer->send('POST', $path, $this->signer->url($path), $body);
            return $this->expectJson($response, 'reportEvents');
        } catch (Throwable $e) {
            error_log('[uengage-platform-sdk] upsell.reportEvents failed: ' . $e->getMessage());
            return ['accepted' => 0];
        }
    }

    // ─── Admin plane (service-actor + capability) ─────────────────────────

    /**
     * GET /v1/upsell/admin/placements — raw config rows, no precedence merge.
     *
     * Omit `parentId` to read the platform defaults.
     *
     * @param array $opts {parentId?: int|string}
     * @return array list of PlacementRecord
     */
    public function adminListPlacements(array $opts = []): array
    {
        $query = '';
        if (isset($opts['parentId'])) {
            $parentId = $this->coercePositiveInt('adminListPlacements.parentId', $opts['parentId']);
            $query = '?' . http_build_query(['parentId' => $parentId]);
        }

        $path = '/v1/upsell/admin/placements';
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $query));

        return $this->expectList($response, 'adminListPlacements');
    }

    /**
     * PUT /v1/upsell/admin/placements — create or replace one config row.
     *
     * A FULL REPLACE, not a patch: `channel`, `key`, `touchpoint`, `enabled`
     * and `display` are all required by the server on every call, so a
     * partial object is a 400 rather than a merge.
     *
     * Scope is derived from what you send — no `parentId` is the platform
     * default, `parentId` alone is a brand rule, `parentId` + `businessId`
     * is one outlet.
     *
     * There is currently no conflict detection: two concurrent writes to the
     * same row last-write-wins with no signal to either caller.
     *
     * @param array $input PlacementUpsertInput
     * @return array the saved PlacementRecord, with a fresh configVersion
     */
    public function adminUpsertPlacement(array $input): array
    {
        $this->assertChannel('adminUpsertPlacement', $input);
        $this->assertNonEmptyString('adminUpsertPlacement.key', $input['key'] ?? null);
        $input = $this->normaliseScopeIds('adminUpsertPlacement', $input);

        $path = '/v1/upsell/admin/placements';
        $body = $this->encode('adminUpsertPlacement', $input);
        $response = $this->signer->send('PUT', $path, $this->signer->url($path), $body);

        return $this->expectJson($response, 'adminUpsertPlacement');
    }

    /**
     * DELETE /v1/upsell/admin/placements — remove one config row.
     *
     * Omitting `parentId` targets the PLATFORM DEFAULT, which removes the
     * placement for every tenant that has not overridden it. The server
     * refuses that unless `confirmDeleteDefault` is true, and this client
     * only sends the flag when you pass it truthy — so a caller who simply
     * forgot `parentId` gets the 400 rather than a silent wipe.
     *
     * Do not pass the string 'false' expecting a no-op. The server coerces
     * the query value, and a non-empty string is truthy there, so 'false'
     * currently satisfies the guard. Omit the key instead.
     *
     * @param array $input {channel, key, parentId?, businessId?, confirmDeleteDefault?: bool}
     */
    public function adminDeletePlacement(array $input): void
    {
        $this->assertChannel('adminDeletePlacement', $input);
        $this->assertNonEmptyString('adminDeletePlacement.key', $input['key'] ?? null);
        $input = $this->normaliseScopeIds('adminDeletePlacement', $input);

        $params = [
            'channel' => $input['channel'],
            'key' => $input['key'],
        ];
        if (isset($input['parentId'])) {
            $params['parentId'] = $input['parentId'];
        }
        if (isset($input['businessId'])) {
            $params['businessId'] = $input['businessId'];
        }
        if (isset($input['confirmDeleteDefault']) && $input['confirmDeleteDefault']) {
            $params['confirmDeleteDefault'] = 'true';
        }

        $path = '/v1/upsell/admin/placements';
        $response = $this->signer->send('DELETE', $path, $this->signer->url($path, '?' . http_build_query($params)));

        // 204 is the only success. Anything else — including a 200 carrying
        // an error body — is a failure, matching the JS client.
        if ($response->getStatus() !== 204) {
            throw new UpsellApiException($response->getStatus(), $response->getBody());
        }
    }

    // ─── Encoding ────────────────────────────────────────────────────────

    /**
     * JSON-encode a request body, or throw with a message a caller can act on.
     *
     * `json_encode` returns `false` on malformed UTF-8 — routine for legacy
     * menu and customer data — and `RequestSigner::send()` declares
     * `?string $body` under `strict_types`, so passing that `false` through
     * is a fatal TypeError rather than a catchable exception.
     *
     * Same try-then-retry shape as `AwsSnsPublisher::encodeMessage`: plain
     * first, then JSON_INVALID_UTF8_SUBSTITUTE on 7.2+, so one bad byte in a
     * customer name degrades to U+FFFD instead of failing the request. The
     * package declares `php >=7.1`, hence the version guard rather than
     * using the constant unconditionally.
     */
    private function encode(string $label, array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            return $json;
        }
        if (PHP_VERSION_ID >= 70200 && json_last_error() === JSON_ERROR_UTF8) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($json !== false) {
                return $json;
            }
        }
        throw new InvalidArgumentException(sprintf(
            'upsell.%s: payload is not encodable as JSON (%s)',
            $label,
            json_last_error_msg()
        ));
    }

    // ─── Validation and normalisation ────────────────────────────────────

    /**
     * Coerce an id to a positive int, accepting the numeric strings legacy
     * callers actually hold.
     *
     * `$_GET`, `$_SESSION` and mysqli all hand back strings, and the server
     * accepts them (`z.coerce.number().int().positive()`), so rejecting '5'
     * would make this client stricter than the API it wraps. `5.0` is
     * accepted for the same reason — that is what an id looks like after
     * json_decode of a config file. `5.5` and 'abc' are not.
     *
     * @param mixed $value
     */
    private function coercePositiveInt(string $label, $value): int
    {
        if (is_bool($value) || !is_numeric($value) || (int) $value != $value || (int) $value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'upsell.%s: expected a positive integer (got %s)',
                $label,
                var_export($value, true)
            ));
        }
        return (int) $value;
    }

    /**
     * @param mixed $value
     */
    private function assertNonEmptyString(string $label, $value): void
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf(
                'upsell.%s: expected a non-empty string (got %s)',
                $label,
                var_export($value, true)
            ));
        }
    }

    private function assertChannel(string $label, array $input): void
    {
        $this->assertNonEmptyString($label . '.channel', $input['channel'] ?? null);
    }

    /**
     * Reads require BOTH ids — a suggestion is always for one outlet.
     *
     * @param mixed $tenant
     * @return array {parentId: int, businessId: int}
     */
    private function normaliseTenant(string $label, $tenant): array
    {
        if (!is_array($tenant)) {
            throw new InvalidArgumentException(sprintf(
                'upsell.%s: tenant must be an array {parentId, businessId}',
                $label
            ));
        }
        return [
            'parentId' => $this->coercePositiveInt($label . '.tenant.parentId', $tenant['parentId'] ?? null),
            'businessId' => $this->coercePositiveInt($label . '.tenant.businessId', $tenant['businessId'] ?? null),
        ];
    }

    /**
     * Validate the request context and re-index the cart lines.
     *
     * `subtotal` is required by the server and is what every price band is a
     * percentage of, so a missing one is checked here rather than discovered
     * as a 400.
     *
     * @param mixed $context
     * @return array
     */
    private function normaliseContext(string $label, $context): array
    {
        $cart = is_array($context) ? ($context['cart'] ?? null) : null;
        if (!is_array($cart) || !isset($cart['items']) || !is_array($cart['items'])) {
            throw new InvalidArgumentException(sprintf(
                'upsell.%s: context.cart.items must be an array (send an empty array for an empty cart)',
                $label
            ));
        }
        if (!isset($cart['subtotal']) || !is_numeric($cart['subtotal']) || $cart['subtotal'] < 0) {
            throw new InvalidArgumentException(sprintf(
                'upsell.%s: context.cart.subtotal must be a non-negative number — the price band '
                    . 'is a percentage of it',
                $label
            ));
        }
        if (count($cart['items']) > self::MAX_CART_ITEMS) {
            throw new InvalidArgumentException(sprintf(
                'upsell.%s: at most %d cart items per call (got %d)',
                $label,
                self::MAX_CART_ITEMS,
                count($cart['items'])
            ));
        }
        // Same re-index as reportEvents: a gapped array encodes as a JSON
        // object and the server's z.array() rejects it.
        $context['cart']['items'] = array_values($cart['items']);

        return $context;
    }

    /**
     * Admin scope ids are both optional — absence is what selects the
     * platform default — but a businessId without a parentId is meaningless
     * and the server rejects it, so catch it here. Present ids are coerced
     * in place so the wire carries ints.
     */
    private function normaliseScopeIds(string $label, array $input): array
    {
        if (isset($input['parentId'])) {
            $input['parentId'] = $this->coercePositiveInt($label . '.parentId', $input['parentId']);
        }
        if (isset($input['businessId'])) {
            $input['businessId'] = $this->coercePositiveInt($label . '.businessId', $input['businessId']);
            if (!isset($input['parentId'])) {
                throw new InvalidArgumentException(sprintf('upsell.%s: businessId requires parentId', $label));
            }
        }
        return $input;
    }

    // ─── Responses ───────────────────────────────────────────────────────

    private function expectJson(HttpResponse $response, string $label): array
    {
        if (!$response->isOk()) {
            throw new UpsellApiException($response->getStatus(), $response->getBody());
        }
        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new UpsellApiException(
                $response->getStatus(),
                sprintf('upsell.%s: response was not valid JSON: %s', $label, $response->getBody())
            );
        }
        return $decoded;
    }

    /**
     * Unwrap a `{placements: [...]}` envelope.
     *
     * A missing key is a broken response shape, not an empty result — and
     * because callers are explicitly told an empty list is normal, the two
     * must not look alike.
     */
    private function expectList(HttpResponse $response, string $label): array
    {
        $body = $this->expectJson($response, $label);
        if (!isset($body['placements']) || !is_array($body['placements'])) {
            throw new UpsellApiException(
                $response->getStatus(),
                sprintf('upsell.%s: response is missing the `placements` array: %s', $label, $response->getBody())
            );
        }
        return $body['placements'];
    }
}
