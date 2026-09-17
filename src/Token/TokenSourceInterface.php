<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Token;

/**
 * A token source produces a Bearer JWT on demand. Implementations
 * encapsulate the auth mode (client_credentials, static bearer,
 * legacy-session exchange).
 *
 * `getAccessToken()` may mint a fresh token (if cache is cold / expired)
 * or return a cached one. `invalidate()` evicts the cached token so the
 * next `getAccessToken()` call re-mints - used by RequestSigner on 401.
 */
interface TokenSourceInterface
{
    public function getAccessToken(): string;

    public function invalidate(): void;
}
