<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Token;

/**
 * Filesystem-backed cache, used as the APCu-less fallback. Cache files
 * live under `$baseDir` (defaulting to sys_get_temp_dir() + a per-SDK
 * subdir) with permissions 0600 so other system users can't read them.
 *
 * Concurrent writers race-but-converge: if two PHP-FPM workers mint a
 * token simultaneously, both write to the same path with rename(2)
 * (atomic on POSIX); the loser's write replaces the winner's, but the
 * value is functionally identical (same access_token + expires_at).
 */
class FileTokenCache implements TokenCacheInterface
{
    /** @var string */
    private $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir !== null
            ? rtrim($baseDir, '/')
            : sys_get_temp_dir() . '/uengage-platform-sdk-php';
        if (!is_dir($this->baseDir)) {
            // 0700 - only the running user can read the cache dir.
            @mkdir($this->baseDir, 0700, true);
        }
    }

    public function get(string $key): ?string
    {
        $path = $this->pathFor($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) === 0) {
            return null;
        }
        // First 10 chars: zero-padded epoch expiry; the rest: the value.
        $expiry = (int) substr($raw, 0, 10);
        if ($expiry <= time()) {
            @unlink($path);
            return null;
        }
        return substr($raw, 10);
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $expiry = time() + $ttlSeconds;
        $payload = sprintf('%010d%s', $expiry, $value);
        $path = $this->pathFor($key);
        // Atomic write: write to a tmp file in the same dir, then rename.
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->pathFor($key));
    }

    private function pathFor(string $key): string
    {
        // sha1 keeps the filename short and ASCII-safe regardless of
        // whatever arbitrary key the SDK derives.
        return $this->baseDir . '/' . sha1($key);
    }
}
