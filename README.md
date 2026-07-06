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

**Auth modes are mutually exclusive.** Picking more than one throws
`ConfigException`. Picking zero is allowed - the client only works
against public endpoints (the openapi spec).

Env defaults (read by `Client::create()` when an option is omitted):
`UENGAGE_BASE_URL`, `UENGAGE_AUTH_BASE_URL`, `UENGAGE_CUSTOMER_AUTH_BASE_URL`,
`UENGAGE_SERVICE_ID`, `UENGAGE_SERVICE_SECRET`, `UENGAGE_SCOPE`,
`UENGAGE_AUTH_TOKEN`, `UENGAGE_SESSION_ID`, `UENGAGE_SESSION_TOKEN`,
`UENGAGE_ACTOR_VIA`.

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
`/v1/audit/openapi.json`, `/auth/business/openapi.json` for the wire
contracts. The PHP namespace structure mirrors the JS SDK 1:1 — refer
to `packages/platform-sdk/src/<namespace>/` for the canonical type
shapes.
