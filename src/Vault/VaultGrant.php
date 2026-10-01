<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Uengage\PlatformSdk\Exceptions\ConfigException;

/**
 * Client-side copy of the vault token contract.
 *
 * The source of truth is `packages/common/src/vault-grant.ts` (and
 * `token-lifetime.ts` for the lifetime bounds). This package is published to
 * Packagist and cannot read the monorepo at runtime, so it keeps a copy;
 * `tests/Vault/VaultGrantDriftTest.php` fails when the two diverge. The
 * values here only drive client-side pre-flight checks - the auth and vault
 * services are authoritative.
 */
final class VaultGrant
{
    const GRANT_TYPE = 'urn:uengage:params:oauth:grant-type:vault-business';
    const TOKEN_USE = 'vault';
    const TOKENS_ISSUE_SCOPE = 'vault.tokens:issue';
    const TYPES_READ_SCOPE = 'vault.types:read';
    const TYPES_WRITE_SCOPE = 'vault.types:write';

    const MIN_TTL_SECONDS = 60;
    const MAX_TTL_SECONDS = 14400;
    const DEFAULT_TTL_SECONDS = 300;

    /** Cached vault tokens are re-minted this many seconds before they expire. */
    const EXPIRY_BUFFER_SECONDS = 60;

    const CREDENTIAL_CLASSES = ['pg', 'pos'];
    const DOCUMENT_CLASSES = ['kyc'];

    /** Every scope a vault token may carry (never `vault.tokens:issue`). */
    const TOKEN_SCOPES = [
        'vault.pg:read',
        'vault.pg:write',
        'vault.pg:decrypt',
        'vault.pos:read',
        'vault.pos:write',
        'vault.pos:decrypt',
        'vault.kyc:read',
        'vault.kyc:write',
        'vault.kyc:view',
        'vault.types:read',
        'vault.types:write',
    ];

    private function __construct()
    {
    }

    /**
     * The checks every vault token response must pass, shared by
     * VaultTokenSource and AuthClient::mintVaultToken so the two cannot
     * drift: a non-empty Bearer token, only vault scopes (exactly the
     * requested set when scopes were asked for), and a lifetime within
     * [MIN_TTL_SECONDS, requested or MAX_TTL_SECONDS].
     *
     * @param mixed $body Decoded JSON response.
     * @param array<int, string>|null $scopes Requested scopes, or null for "all allowed".
     * @return array{accessToken:string,expiresIn:int,scope:string}|null
     */
    public static function checkTokenResponse($body, ?array $scopes, ?int $expiresIn): ?array
    {
        if (!is_array($body)
            || !isset($body['access_token'], $body['token_type'], $body['scope'], $body['expires_in'])
            || !is_string($body['access_token']) || $body['access_token'] === ''
            || $body['token_type'] !== 'Bearer' || !is_string($body['scope'])
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

    /**
     * Normalise a legacy integer business id (int or decimal string) to the
     * decimal string form tokens carry.
     *
     * @param int|string $value
     */
    public static function normaliseId($value, string $label): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,15}$/', $value) !== 1) {
            throw new ConfigException(sprintf(
                'vault: %s must be a positive integer id (int or decimal string)',
                $label
            ));
        }
        return $value;
    }

    /**
     * @param int|string $parentId
     * @param int|string|null $businessId
     * @return array{parentId:string,businessId?:string}
     */
    public static function tenant($parentId, $businessId = null): array
    {
        $tenant = ['parentId' => self::normaliseId($parentId, 'parentId')];
        if ($businessId !== null) {
            $tenant['businessId'] = self::normaliseId($businessId, 'businessId');
        }
        return $tenant;
    }

    public static function assertExpiresIn(int $expiresIn): void
    {
        if ($expiresIn < self::MIN_TTL_SECONDS || $expiresIn > self::MAX_TTL_SECONDS) {
            throw new ConfigException(sprintf(
                'vault: expiresIn must be an integer between %d and %d seconds',
                self::MIN_TTL_SECONDS,
                self::MAX_TTL_SECONDS
            ));
        }
    }

    /**
     * @param array<int, string> $scopes
     */
    public static function assertScopes(array $scopes): void
    {
        if (count($scopes) === 0) {
            throw new ConfigException('vault: scopes must not be empty; omit it for every allowed scope');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !in_array($scope, self::TOKEN_SCOPES, true)) {
                throw new ConfigException(sprintf(
                    'vault: %s is not a vault token scope',
                    is_string($scope) ? $scope : gettype($scope)
                ));
            }
        }
    }
}
