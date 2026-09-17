<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Token;

/**
 * APCu-backed cache. Shared across PHP-FPM workers on the same host,
 * no external dependency. Requires ext-apcu loaded; constructor throws
 * if unavailable so the caller can fall back to FileTokenCache.
 *
 * Static `isAvailable()` lets the factory pick a cache backend without
 * a try/catch.
 */
class ApcuTokenCache implements TokenCacheInterface
{
    /** @var string */
    private $keyPrefix;

    public function __construct(string $keyPrefix = 'uengage_platform_sdk:')
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException(
                'ApcuTokenCache requires ext-apcu to be loaded and enabled'
            );
        }
        $this->keyPrefix = $keyPrefix;
    }

    public static function isAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key): ?string
    {
        $success = false;
        $value = apcu_fetch($this->keyPrefix . $key, $success);
        return $success && is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        apcu_store($this->keyPrefix . $key, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        apcu_delete($this->keyPrefix . $key);
    }
}
