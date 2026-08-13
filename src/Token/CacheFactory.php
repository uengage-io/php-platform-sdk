<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Token;

/**
 * Picks the best available token cache backend without the caller
 * having to reason about extension availability. Priority order:
 *
 *   1. APCu (if ext-apcu is loaded and enabled - shared across
 *      PHP-FPM workers on the host)
 *   2. File (always available - sys_get_temp_dir, atomic rename)
 *   3. InMemory (only if both above are explicitly disabled)
 *
 * Callers who want their own backend (Redis, etc) should construct it
 * directly and pass to `createClient(['cache' => $myCache])`.
 */
class CacheFactory
{
    public static function preferred(): TokenCacheInterface
    {
        if (ApcuTokenCache::isAvailable()) {
            return new ApcuTokenCache();
        }
        return new FileTokenCache();
    }
}
