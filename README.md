# uengage.io/php-platform-sdk

PHP client SDK for the uEngage platform API. Mirrors the JS SDK
(`@uengage.io/platform-sdk`) — same five namespaces (`zones`,
`business`, `audit`, `auth`, `wallet`), same auth modes, same error envelope.

- **Base URL** (default): `https://api.platform.uengage.io`
- **PHP**: 7.1+
- **Deps**: ext-curl, ext-json (no Guzzle, no other runtime deps)

## Install

```bash
composer require uengage.io/php-platform-sdk
```

If you have not configured Packagist yet, point at the mirror repo
directly in `composer.json`:

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/uengage-io/php-platform-sdk" }]
}
```

## Quick start

```php
use Uengage\PlatformSdk\Client;

$platform = Client::create([
    'serviceId' => 'edge-zones-admin',
    'serviceSecret' => getenv('EDGE_ZONES_ADMIN_SECRET'),
]);

// Zones - the new spatial primitive
$zone = $platform->zones->create([
    'geometry' => [
        'type' => 'Polygon',
        'coordinates' => [[
            [77.5, 12.9], [77.6, 12.9], [77.6, 13.0], [77.5, 13.0], [77.5, 12.9],
        ]],
    ],
    'tags' => ['type' => 'delivery-area', 'city' => 'BLR'],
]);

$matches = $platform->zones->containing([
    'point' => ['lat' => 12.97, 'lng' => 77.59],
    'tags' => ['type' => 'delivery-area'],
]);

// Business read
$record = $platform->business->get(42, ['profile']);

// Audit (buffered; flushed at shutdown or on demand)
$platform->audit->record([
    'event_type' => 'business.profile_updated',
    'tenant' => ['id' => '42', 'parent_id' => null],
    'actor' => ['type' => 'service', 'id' => 'edge-zones-admin'],
    'resource' => ['type' => 'business', 'id' => '42'],
    'changes' => ['name' => ['before' => 'Old', 'after' => 'New']],
]);
$platform->audit->flush(); // optional; shutdown hook will best-effort flush

// Wallet — getWallet(...) returns a handle bound to one business.
// Needs wallet.balance:read (reads) / wallet.transactions:read|write (writes).
$wallet = $platform->wallet->getWallet(['id' => 'business:8841']);

$balance = $wallet->getBalance();           // ['balance'=>float, 'balanceMinor'=>int, 'currency'=>['code'=>..,'symbol'=>..]]
$currency = $wallet->getCurrency();          // ['code'=>'INR', 'symbol'=>'₹']

$txn = $wallet->credit([                     // or ->debit([...])
    'referenceId' => 'order-12345',          // idempotency key
    'amountMinor' => 1180,                    // ₹11.80, in integer minor units of the wallet currency
    'service'     => 0,                       // legacy service_id (0=RECHARGE, 5=FLASH_DELIVERY, ...)
    'description' => 'wallet top-up',
    'tags'        => ['source' => 'edge'],
    // 'reversalOf' => '<debit id>',           // on credit → a refund
    // 'allowNegative' => true,                // on debit → permit overdraw
]);

$page = $wallet->listTransactions(['type' => 'debit', 'limit' => 20]); // keyset-paginated
$one  = $wallet->getTransaction($txn['id']);
```

## Configuration

`Client::create([...])` takes the same options as the JS SDK:

| Option                        | Type                            | Default                                           |
| ----------------------------- | ------------------------------- | ------------------------------------------------- |
| `baseUrl`                     | string                          | `https://api.platform.uengage.io`                 |
| `authBaseUrl`                 | string                          | `{baseUrl}/auth/business`                         |
| `customerAuthBaseUrl`         | string                          | `{baseUrl}/auth/customer`                         |
| `serviceId` + `serviceSecret` | string                          | OAuth2 client_credentials mode                    |
| `authToken`                   | string                          | static Bearer mode (caller owns freshness)        |
| `session`                     | `['id' => ..., 'token' => ...]` | legacy uEngage session-exchange mode              |
| `scope`                       | string (optional)               | space-separated scope list for client_credentials |
| `actorVia`                    | string                          | stamped into audit `actor.via`                    |
| `cache`                       | `TokenCacheInterface`           | APCu if loaded, else file-on-disk                 |
| `http`                        | `HttpClient`                    | default (cURL backend)                            |
| `eventsTopicArn`              | string                          | SNS topic ARN for the platform event bus          |
| `eventsRegion`                | string                          | defaults to the region inside `eventsTopicArn`    |
| `eventSource`                 | string                          | envelope `source` field, default `legacy-php`     |
| `snsPublisher`                | `SnsPublisherInterface`         | default: `AwsSnsPublisher` (aws/aws-sdk-php)      |

**Auth modes are mutually exclusive.** Picking more than one throws
`ConfigException`. Picking zero is allowed - the client only works
against public endpoints (the openapi spec).

Env defaults (read by `Client::create()` when an option is omitted):
`UENGAGE_BASE_URL`, `UENGAGE_AUTH_BASE_URL`, `UENGAGE_CUSTOMER_AUTH_BASE_URL`,
`UENGAGE_SERVICE_ID`, `UENGAGE_SERVICE_SECRET`, `UENGAGE_SCOPE`,
`UENGAGE_AUTH_TOKEN`, `UENGAGE_SESSION_ID`, `UENGAGE_SESSION_TOKEN`,
`UENGAGE_ACTOR_VIA`, `PLATFORM_EVENTS_TOPIC_ARN`, `PLATFORM_EVENTS_REGION`,
`PLATFORM_EVENTS_SOURCE`.

## Events client (platform event bus)

Publishes order-lifecycle events onto the platform's SNS bus. This is the one
namespace that does not go through the platform HTTP API — it publishes
straight to SNS with the host's AWS credentials (on legacy, the EC2 instance
role), so there is no token to mint and no extra hop on the order path.

```php
$platform->events->publish('order.status_changed', [
    'orderId' => $order->id,
    'orderType' => $order->type,
    'status' => $order->status,
    'deliveryStatus' => $order->deliveryStatus,
    'statusRank' => $rank,
], ['tenantId' => $order->businessId]);
```

- `publish()` buffers in memory and returns immediately. The queue is flushed
  at request end via `register_shutdown_function`, in `PublishBatch` calls of
  up to 10, with short timeouts.
- **The shutdown flush stays off the request's clock.** It calls
  `fastcgi_finish_request()` first where the SAPI has it (PHP-FPM), so the
  response is already closed before anything talks to SNS, and it is capped at
  `SHUTDOWN_MAX_CHUNKS` (5 batches = 50 events) even then — because closing the
  client still leaves an FPM worker held, and workers are the scarce resource
  under load. Against a dead bus the worst case is ~15s of worker time, not
  minutes. Events past the cap are counted and logged, not retried.
  An explicit `flush()` is uncapped: that is the caller choosing to wait.
- **Fail-open, unconditionally.** `flush()` catches everything, writes to
  `error_log()`, and returns. A bus outage, an expired instance role, or a
  missing AWS SDK can never fail an order status change. Call `flush()`
  yourself and check `failedCount()` if you want to observe failures.
- The SDK stamps `id` (monotonic ULID), `occurredAt`, `version`, `domain`, and
  `source`. Call sites supply type, payload, and tenant.
- `publish()` _does_ throw `InvalidArgumentException` for a malformed type, an
  unmapped domain, a missing `tenantId`, or list-shaped `data` — those are
  call-site bugs, not runtime conditions, and failing open on them would just
  fill a DLQ.
- Each envelope is JSON-encoded **individually** before the batch is assembled.
  Malformed UTF-8 (realistic: customer names come straight out of the legacy
  DB) is substituted on PHP 7.2+ and, failing that, drops just that one entry
  with a log — its nine batch siblings still go out. Envelopes over the SNS
  256 KB message limit are dropped the same way.

### Requires `aws/aws-sdk-php`

It is a **suggested**, not a required, dependency: this SDK is installed by
consumers that only use zones/business/audit, and a hard requirement would drag
the AWS SDK — and its PHP >= 7.2.5 floor — into all of them. Applications that
publish events add it themselves:

```bash
composer require aws/aws-sdk-php
```

Without it, `publish()` still buffers and `flush()` fails open with an
`error_log()` entry. Hosts that already own a configured `SnsClient` can pass
`'snsPublisher' => new AwsSnsPublisher($region, $existingClient)`, or any
`SnsPublisherInterface`.

## Upsell client

Cart-aware upsell suggestions. Three calls carry a storefront integration:
ask which slots exist, ask what goes in one, report what happened.

```php
// 1. Which slots does this screen have? Once per screen, cacheable.
$placements = $platform->upsell->placements([
    'channel'    => 'whitelabel_web',
    'parentId'   => 5,   // the brand
    'businessId' => 6,   // the outlet being ordered from
]);

// 2. What goes in one? Re-ask whenever the cart changes.
$result = $platform->upsell->suggest([
    'channel'   => 'whitelabel_web',
    'placement' => 'cart_recommendation',
    'tenant'    => ['parentId' => 5, 'businessId' => 6],
    'context'   => [
        'cart' => [
            'subtotal' => 500,
            'items'    => [
                [
                    'itemId'      => 101,
                    'qty'         => 1,
                    'unitPrice'   => 400,
                    'itemSlug'    => 'margherita',
                    'sectionName' => 'Pizzas',  // drives category no-repeat
                    'veg'         => 1,         // drives the dietary rule
                ],
            ],
        ],
        'fulfilmentMode' => 'delivery',
        'sessionId'      => session_id(),
    ],
]);

if ($result['items'] === []) {
    return '';  // a normal answer — render nothing at all
}

// 3. Report what happened. Fails open — no try/catch needed: a timeout,
//    an expired token or a 500 is logged and returns ['accepted' => 0].
$platform->upsell->reportEvents([
    'channel'       => 'whitelabel_web',
    'placement'     => 'cart_recommendation',
    'tenant'        => ['parentId' => 5, 'businessId' => 6],
    'sessionId'     => session_id(),
    'configVersion' => $result['meta']['configVersion'],
    'events'        => [
        ['type' => 'shown', 'itemId' => 48346322, 'reasonCode' => 'popular_now'],
        ['type' => 'added', 'itemId' => 48346322],
    ],
]);
```

Six things that are not obvious from the types:

- **An empty `items` list is a normal outcome, not an error.** The price band
  is a percentage of the cart subtotal, so a small cart legitimately has
  nothing to suggest. Render nothing — no card, no heading, no empty state.
- **Send `sectionName` and `veg` on every cart line.** Both are optional in
  the schema and load-bearing in the engine: `veg` drives the dietary rule and
  only engages when _every_ line carries it, and `sectionName` drives the
  don't-repeat-a-cart-category rule. Omitting them silently disables both.
- **Size your layout off `meta.maxItems`, not `display.maxItems`.** The first
  is what the merchant asked for; the second is what the touchpoint policy
  allowed. A slot configured for 6 reports 3 at the cart and 2 at checkout.
- **`display.layout` is opaque** — the platform never interprets it. Map it to
  your own component with a fallback, so a merchant picking a new layout does
  not need a release.
- **`reportEvents` never throws for I/O.** A timeout, DNS failure, expired
  token or 5xx is logged via `error_log` and returns `['accepted' => 0]`, so
  telemetry cannot take down a cart render. Caller mistakes — a missing tenant,
  an empty batch, more than 50 events — still throw, because those are bugs to
  fix rather than conditions to survive.
- **Ids may be numeric strings.** `'5'` from `$_GET`, `$_SESSION` or mysqli is
  accepted and coerced, matching the server. `5.5` and `'abc'` are rejected.
  Build event and cart-item lists with `array_values()` if you filtered them —
  the client re-indexes for you, but a gapped array would otherwise encode as a
  JSON object and the server rejects that.

### Admin plane

Placement configuration. These require a **service-actor** token carrying
`upsell.placements:read` / `:write`; a user token gets a 403.

```php
$rows = $platform->upsell->adminListPlacements(['parentId' => 5]);

$platform->upsell->adminUpsertPlacement([
    'parentId'   => 5,
    'channel'    => 'whitelabel_web',
    'key'        => 'cart_recommendation',
    'touchpoint' => 'cart',
    'enabled'    => true,
    'display'    => [
        'title'    => 'Complete your order',
        'maxItems' => 6,
        'layout'   => 'grid_2xn_modal',
    ],
]);

$platform->upsell->adminDeletePlacement([
    'parentId' => 5,
    'channel'  => 'whitelabel_web',
    'key'      => 'cart_recommendation',
]);
```

`adminUpsertPlacement` is a **full replace, not a patch** — `channel`, `key`,
`touchpoint`, `enabled` and `display` are required on every call, so a partial
object is a 400 rather than a merge. Only `strategy`, `strategyParams`,
`display.maxItems` and `display.layout` have server-side defaults. Scope is
derived from what you send: no `parentId` is the platform default, `parentId`
alone is a brand rule, `parentId` + `businessId` is one outlet.

Deleting without a `parentId` targets the **platform default**, removing the
placement for every tenant that has not overridden it, and needs
`'confirmDeleteDefault' => true`. This client only sends that flag when you
pass it truthy, so forgetting `parentId` gets you a 400 rather than a silent
wipe.

Non-2xx responses throw `UpsellApiException` (`getStatus()`, `getBody()`).

## Token caching

By default `Client::create()` picks the best available cache:

1. **APCu** (`Uengage\PlatformSdk\Token\ApcuTokenCache`) - if ext-apcu
   is loaded and enabled. Shared across PHP-FPM workers on the host;
   recommended for production.
2. **File** (`Uengage\PlatformSdk\Token\FileTokenCache`) - falls back
   to `sys_get_temp_dir()/uengage-platform-sdk-php/`. Atomic writes,
   0600 permissions. Works everywhere.

Plug a custom backend (Redis, Memcached, your app's cache pool) by
implementing `TokenCacheInterface` and passing `'cache' => $yours` to
`Client::create()`.

For one-off scripts or tests where multi-request reuse doesn't
matter, use `InMemoryTokenCache`.

## Error handling

The SDK throws typed exceptions:

| Exception                                                | When                                                          |
| -------------------------------------------------------- | ------------------------------------------------------------- |
| `Uengage\PlatformSdk\Exceptions\ConfigException`         | bad client construction (multiple auth modes, etc)            |
| `Uengage\PlatformSdk\Exceptions\AuthenticationException` | token mint rejected by auth surface                           |
| `Uengage\PlatformSdk\Zones\ZonesApiException`            | non-2xx from `/v1/zones/*`                                    |
| `Uengage\PlatformSdk\Wallet\WalletApiException`          | non-2xx from `/v1/wallet/*` (->errorCode(), ->balanceMinor()) |
| `Uengage\PlatformSdk\Business\BusinessApiException`      | non-2xx from `/v1/businesses/*`                               |
| `Uengage\PlatformSdk\Audit\AuditApiException`            | non-2xx from `/v1/audit/events`                               |
| `Uengage\PlatformSdk\Auth\AuthApiException`              | non-2xx from `/auth/business/*`                               |
| `InvalidArgumentException`                               | bad local input (non-uuid, out-of-range lat/lng, etc)         |

All `*ApiException` types extend `ApiException` and expose
`getStatus(): int` + `getBody(): string`.

The SDK transparently rotates the token + retries once on a 401 when
the auth mode supports invalidation, so most expiry-related 401s
never surface to your code.

## Testing the SDK locally

```bash
composer install
vendor/bin/phpunit
```

## API surface — full reference

See `/v1/zones/openapi.json`, `/v1/businesses/openapi.json`,
`/v1/audit/openapi.json`, `/v1/upsell/openapi.json`,
`/auth/business/openapi.json` for the wire contracts. The PHP namespace structure mirrors the JS SDK 1:1 — refer
to `packages/platform-sdk/src/<namespace>/` for the canonical type
shapes.
