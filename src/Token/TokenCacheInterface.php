<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Token;

/**
 * Pluggable token storage. The SDK ships APCu and File implementations
 * and an InMemory implementation for tests; callers can plug Redis /
 * Memcached / any custom backend by implementing this interface.
 *
 * Keys are opaque to the cache; the SDK derives a stable key from the
 * (clientId, scope, authBaseUrl) tuple so two different consumers
 * sharing the same cache backend (rare) don't collide.
 *
 * Values are JSON strings carrying `{access_token, expires_at}`.
 * Implementations don't need to inspect the value - they only need to
 * honour the TTL (`$ttlSeconds` on `set`).
 */
interface TokenCacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
