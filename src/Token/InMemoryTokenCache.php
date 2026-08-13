<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Token;

/**
 * Process-local cache. The cache dies with the PHP process; useful
 * for CLI scripts, tests, and anywhere multi-request reuse is not
 * needed.
 */
class InMemoryTokenCache implements TokenCacheInterface
{
    /** @var array<string, array{value: string, expiresAt: int}> */
    private $store = [];

    public function get(string $key): ?string
    {
        if (!isset($this->store[$key])) {
            return null;
        }
        $entry = $this->store[$key];
        if ($entry['expiresAt'] <= time()) {
            unset($this->store[$key]);
            return null;
        }
        return $entry['value'];
    }

    public function set(string $key, string $value, int $ttlSeconds): void
    {
        $this->store[$key] = [
            'value' => $value,
            'expiresAt' => time() + $ttlSeconds,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }
}
