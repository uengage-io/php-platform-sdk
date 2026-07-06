<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Token;

/**
 * A token source that always returns the same caller-supplied Bearer
 * JWT. Used when the caller already has a token (e.g. a server-side
 * proxy passing through an upstream session JWT) and wants the SDK to
 * just sign requests without minting.
 *
 * `invalidate()` is a no-op - we have nowhere to re-mint from.
 */
class StaticBearerTokenSource implements TokenSourceInterface
{
    /** @var string */
    private $token;

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function getAccessToken(): string
    {
        return $this->token;
    }

    public function invalidate(): void
    {
        // Nothing to invalidate.
    }
}
