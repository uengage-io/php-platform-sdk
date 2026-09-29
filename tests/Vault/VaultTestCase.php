<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Vault;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Token\ClientCredentialsTokenSource;
use Uengage\PlatformSdk\Token\InMemoryTokenCache;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;
use Uengage\PlatformSdk\Vault\VaultClient;

/**
 * Shared fixtures: a StubHttp, and VaultClients in each auth mode.
 */
abstract class VaultTestCase extends TestCase
{
    const BASE = 'https://api.test';
    const AUTH = 'https://api.test/auth/business';

    /** @var StubHttp */
    protected $stub;

    /** @var InMemoryTokenCache */
    protected $cache;

    protected function setUp(): void
    {
        foreach (['UENGAGE_BASE_URL', 'UENGAGE_AUTH_BASE_URL', 'UENGAGE_SERVICE_ID', 'UENGAGE_SERVICE_SECRET',
            'UENGAGE_AUTH_TOKEN', 'UENGAGE_SESSION_ID', 'UENGAGE_SESSION_TOKEN', 'UENGAGE_SCOPE'] as $key) {
            putenv($key);
        }
        $this->stub = new StubHttp(self::BASE);
        $this->cache = new InMemoryTokenCache();
    }

    /**
     * Unsigned JWT - the SDK only decodes it.
     *
     * @param array<string, mixed> $claims
     */
    protected static function jwt(array $claims): string
    {
        $enc = function ($data) {
            return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        };
        return $enc(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $enc($claims) . '.sig';
    }

    protected static function vaultJwt(string $parentId, ?string $businessId = null, int $ttl = 300): string
    {
        $tenant = ['parent_id' => $parentId];
        if ($businessId !== null) {
            $tenant['business_id'] = $businessId;
        }
        return self::jwt([
            'token_use' => 'vault',
            'tenant' => $tenant,
            'scope' => 'vault.pg:read',
            'iat' => time(),
            'exp' => time() + $ttl,
        ]);
    }

    protected function credentialsClient(): VaultClient
    {
        $config = new Config(
            self::BASE,
            self::AUTH,
            self::BASE . '/auth/customer',
            new ClientCredentialsTokenSource($this->stub->client, $this->cache, self::AUTH, 'svc', 's3cret')
        );
        return new VaultClient($config, $this->stub->client, $this->cache, 'svc', 's3cret');
    }

    protected function staticClient(?string $token, ?callable $getToken = null): VaultClient
    {
        $config = new Config(
            self::BASE,
            self::AUTH,
            self::BASE . '/auth/customer',
            $token === null ? null : new StaticBearerTokenSource($token)
        );
        return new VaultClient($config, $this->stub->client, null, null, null, $getToken);
    }

    protected function pushMint(string $token = 'vault.jwt', int $expiresIn = 300): void
    {
        $this->stub->pushJson(200, [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expiresIn,
            'scope' => 'vault.pg:read vault.pg:decrypt',
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected static function form(array $call): array
    {
        parse_str((string) $call['body'], $out);
        return $out;
    }

    /**
     * @return mixed
     */
    protected static function jsonBody(array $call)
    {
        return json_decode((string) $call['body'], true);
    }
}
