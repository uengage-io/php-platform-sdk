<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Alerts;

/**
 * Client-side copy of the alerts user token contract.
 *
 * The source of truth is `packages/common/src/alerts-grant.ts` (and
 * `token-lifetime.ts` for the lifetime bounds). This package cannot read the
 * monorepo at runtime, so it keeps a copy; `tests/Alerts/AlertsGrantDriftTest.php`
 * fails when the two diverge. The values only drive client-side pre-flight
 * checks - the auth and alerts services are authoritative.
 */
final class AlertsGrant
{
    const GRANT_TYPE = 'urn:uengage:params:oauth:grant-type:alerts-user';
    const TOKEN_USE = 'alerts';
    const TOKENS_ISSUE_SCOPE = 'alerts.tokens:issue';
    const READ_SCOPE = 'alerts:read';
    const WRITE_SCOPE = 'alerts:write';

    /** Every scope an alerts user token may carry (never `alerts.tokens:issue`). */
    const TOKEN_SCOPES = ['alerts:read', 'alerts:write'];

    const MIN_TTL_SECONDS = 60;
    const MAX_TTL_SECONDS = 14400;
    const DEFAULT_TTL_SECONDS = 300;

    /** Dashboard user ids: 1-64 printable ASCII, no spaces. */
    const USER_ID_PATTERN = '/\A[\x21-\x7e]{1,64}\z/';

    private function __construct()
    {
    }

    public static function isUserId(string $userId): bool
    {
        return preg_match(self::USER_ID_PATTERN, $userId) === 1;
    }

    /**
     * The checks every alerts token response must pass: a non-empty Bearer
     * token, only alerts scopes (exactly the requested set when scopes were
     * asked for), the requested user, and a lifetime within
     * [MIN_TTL_SECONDS, requested or MAX_TTL_SECONDS].
     *
     * @param mixed $body Decoded JSON response.
     * @param array<int, string>|null $scopes Requested scopes, or null for "all allowed".
     * @return array{accessToken:string,expiresIn:int,scope:string}|null
     */
    public static function checkTokenResponse($body, ?array $scopes, ?int $expiresIn, string $userId): ?array
    {
        if (!is_array($body)
            || !isset($body['access_token'], $body['token_type'], $body['scope'], $body['expires_in'], $body['user_id'])
            || !is_string($body['access_token']) || $body['access_token'] === ''
            || $body['token_type'] !== 'Bearer' || !is_string($body['scope'])
            || $body['user_id'] !== $userId
        ) {
            return null;
        }
        $granted = array_values(array_filter(explode(' ', $body['scope']), 'strlen'));
        if ($granted === [] || array_diff($granted, self::TOKEN_SCOPES) !== []) {
            return null;
        }
        if ($scopes !== null) {
            $requested = array_values(array_unique($scopes));
            if (count($granted) !== count($requested) || array_diff($granted, $requested) !== []) {
                return null;
            }
        }
        $ceiling = $expiresIn !== null ? $expiresIn : self::MAX_TTL_SECONDS;
        if (!is_int($body['expires_in'])
            || $body['expires_in'] < self::MIN_TTL_SECONDS
            || $body['expires_in'] > $ceiling
        ) {
            return null;
        }
        return [
            'accessToken' => $body['access_token'],
            'expiresIn' => $body['expires_in'],
            'scope' => $body['scope'],
        ];
    }
}
