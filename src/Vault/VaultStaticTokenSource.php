<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Token\TokenSourceInterface;

/**
 * Static-token mode: a vault token handed in by a backend that minted it
 * with `$platform->auth->mintVaultToken()` (`authToken` / UENGAGE_AUTH_TOKEN), optionally renewed
 * through a caller-supplied `getToken` callable.
 *
 * `getToken` returns either the token string or
 * `['accessToken' => ..., 'expiresIn' => seconds]`. It is called when there
 * is no token yet, when the current one has expired (by `expiresIn`, else the
 * JWT's `exp`), and after a 401 - in which case the request is retried once.
 * Without `getToken` a 401 is final.
 */
class VaultStaticTokenSource implements TokenSourceInterface
{
    /** @var TokenSourceInterface|null */
    private $inner;

    /** @var callable|null */
    private $getToken;

    /** @var string|null */
    private $token;

    /** @var int|null Unix seconds. */
    private $expiresAt;

    /** @var bool */
    private $stale = false;

    public function __construct(?TokenSourceInterface $inner, ?callable $getToken = null)
    {
        $this->inner = $inner;
        $this->getToken = $getToken;
    }

    public function canRefresh(): bool
    {
        return $this->getToken !== null;
    }

    public function getAccessToken(): string
    {
        if ($this->token === null && !$this->stale && $this->inner !== null) {
            $this->token = $this->inner->getAccessToken();
            $this->expiresAt = self::expiryOf($this->token);
        }
        $expired = $this->token !== null && $this->expiresAt !== null && $this->expiresAt <= time() + 5;
        if ($this->getToken !== null && ($this->token === null || $this->stale || $expired)) {
            $this->refresh();
        }
        if ($this->token === null) {
            throw new ConfigException(
                'vault: no token configured. Pass serviceId + serviceSecret, authToken, or getToken to Client::create().'
            );
        }
        return $this->token;
    }

    public function invalidate(): void
    {
        if ($this->getToken !== null) {
            $this->stale = true;
        }
    }

    private function refresh(): void
    {
        $result = call_user_func($this->getToken);
        $expiresIn = null;
        if (is_array($result)) {
            $expiresIn = isset($result['expiresIn']) && is_int($result['expiresIn']) && $result['expiresIn'] > 0
                ? $result['expiresIn']
                : null;
            $result = isset($result['accessToken']) ? $result['accessToken'] : null;
        }
        if (!is_string($result) || $result === '') {
            throw new ConfigException(
                'vault: getToken must return a token string or [\'accessToken\' => ..., \'expiresIn\' => ...]'
            );
        }
        $this->token = $result;
        $this->expiresAt = $expiresIn !== null ? time() + $expiresIn : self::expiryOf($result);
        $this->stale = false;
    }

    /**
     * Decode (without verifying) a JWT's payload.
     *
     * @return array<string, mixed>|null
     */
    public static function claimsOf(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $b64 = strtr($parts[1], '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $json = base64_decode($b64, true);
        if ($json === false) {
            return null;
        }
        $claims = json_decode($json, true);
        return is_array($claims) ? $claims : null;
    }

    /**
     * The `tenant` claim of a vault token, in SDK shape, or null.
     *
     * @return array{parentId:string,businessId?:string}|null
     */
    public static function tenantOf(string $jwt): ?array
    {
        $claims = self::claimsOf($jwt);
        if ($claims === null || !isset($claims['tenant']) || !is_array($claims['tenant'])) {
            return null;
        }
        $t = $claims['tenant'];
        if (!isset($t['parent_id']) || !is_string($t['parent_id'])) {
            return null;
        }
        $tenant = ['parentId' => $t['parent_id']];
        if (isset($t['business_id']) && is_string($t['business_id'])) {
            $tenant['businessId'] = $t['business_id'];
        }
        return $tenant;
    }

    private static function expiryOf(string $jwt): ?int
    {
        $claims = self::claimsOf($jwt);
        return $claims !== null && isset($claims['exp']) && is_int($claims['exp']) ? $claims['exp'] : null;
    }
}
