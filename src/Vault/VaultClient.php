<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Http\HttpClient;
use Uengage\PlatformSdk\Token\InMemoryTokenCache;
use Uengage\PlatformSdk\Token\TokenCacheInterface;

/**
 * `$platform->vault` - encrypted per-brand credentials and KYC documents.
 *
 * Vault accepts only vault tokens, which are bound to one tenant (a brand,
 * or one outlet of it). Where the token comes from depends on how the
 * client was built:
 *
 *   Client credentials (serviceId + serviceSecret) - backend:
 *     $vault = $platform->vault->forTenant(5, 7175);
 *     The SDK mints a vault token for that tenant, caches it until 60 s
 *     before expiry and re-mints once on a 401.
 *
 *   Static token (authToken, optionally getToken) - a vault token minted
 *   elsewhere with $platform->auth->mintVaultToken(...):
 *     $platform->vault->credentials()->list(['class' => 'pg']);
 *     The tenant comes from the token. forTenant() is optional and only
 *     asserts that the token is for that tenant.
 *
 * To mint a token for someone else (a frontend), use
 * $platform->auth->mintVaultToken(...) with client credentials.
 */
class VaultClient
{
    /** @var Config */
    private $config;

    /** @var HttpClient */
    private $http;

    /** @var TokenCacheInterface */
    private $cache;

    /** @var string|null */
    private $clientId;

    /** @var string|null */
    private $clientSecret;

    /** @var VaultStaticTokenSource|null */
    private $staticSource;

    /** @var VaultHandle|null */
    private $defaultHandle;

    /** @var Types|null */
    private $serviceTypes;

    /** @var array<string, VaultHandle> */
    private $handles = [];

    public function __construct(
        Config $config,
        HttpClient $http,
        ?TokenCacheInterface $cache = null,
        ?string $clientId = null,
        #[\SensitiveParameter]
        ?string $clientSecret = null,
        ?callable $getToken = null
    ) {
        if (($clientId === null) !== ($clientSecret === null)) {
            throw new ConfigException('vault: clientId and clientSecret must be passed together');
        }
        if ($clientId !== null && $getToken !== null) {
            throw new ConfigException(
                'vault: getToken is for static-token mode; with serviceId + serviceSecret the SDK mints vault tokens itself'
            );
        }
        $this->config = $config;
        $this->http = $http;
        $this->cache = $cache !== null ? $cache : new InMemoryTokenCache();
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        if ($clientId === null) {
            $this->staticSource = new VaultStaticTokenSource($config->getTokenSource(), $getToken);
        }
    }

    /** True when the SDK mints vault tokens itself (client credentials). */
    public function hasClientCredentials(): bool
    {
        return $this->clientId !== null;
    }

    /**
     * A handle bound to one tenant.
     *
     * With client credentials, mints (and caches) a vault token for exactly
     * this tenant. With a static token, checks the token's `tenant` claim
     * (decoded, not verified) and throws ConfigException on a mismatch.
     *
     * @param int|string $parentId The brand.
     * @param int|string|null $businessId The outlet, or null for the brand.
     */
    public function forTenant($parentId, $businessId = null): VaultHandle
    {
        $tenant = VaultGrant::tenant($parentId, $businessId);
        $key = $tenant['parentId'] . ':' . (isset($tenant['businessId']) ? $tenant['businessId'] : '-');
        if (isset($this->handles[$key])) {
            return $this->handles[$key];
        }

        if ($this->clientId !== null) {
            $source = new VaultTokenSource(
                $this->http,
                $this->cache,
                $this->config->getAuthBaseUrl(),
                $this->clientId,
                (string) $this->clientSecret,
                $tenant
            );
            return $this->handles[$key] = new VaultHandle(
                new VaultTransport($this->config, $this->http, $source),
                $tenant
            );
        }

        $tokenTenant = VaultStaticTokenSource::tenantOf($this->staticSource->getAccessToken());
        if ($tokenTenant === null) {
            throw new ConfigException('vault: the configured token carries no vault tenant; is it a vault token?');
        }
        if ($tokenTenant !== $tenant) {
            throw new ConfigException(sprintf(
                'vault: forTenant(%s) does not match the token\'s tenant (%s)',
                self::describe($tenant),
                self::describe($tokenTenant)
            ));
        }
        return $this->handles[$key] = new VaultHandle(
            new VaultTransport($this->config, $this->http, $this->staticSource),
            $tenant
        );
    }

    /** Static-token mode: credentials of the token's tenant. */
    public function credentials(): Credentials
    {
        return $this->defaultHandle()->credentials();
    }

    /** Static-token mode: documents of the token's tenant. */
    public function documents(): Documents
    {
        return $this->defaultHandle()->documents();
    }

    /**
     * The type registry. With client credentials this uses the ordinary
     * service token (it must hold `vault.types:read` / `:write`); with a
     * static token, that token.
     */
    public function types(): Types
    {
        if ($this->clientId === null) {
            return $this->defaultHandle()->types();
        }
        if ($this->serviceTypes === null) {
            $this->serviceTypes = new Types(
                new VaultTransport($this->config, $this->http, $this->config->getTokenSource())
            );
        }
        return $this->serviceTypes;
    }

    private function defaultHandle(): VaultHandle
    {
        if ($this->clientId !== null) {
            throw new ConfigException(
                'vault: with client credentials, pick a tenant first: $platform->vault->forTenant($parentId, $businessId)'
            );
        }
        if ($this->defaultHandle === null) {
            $this->defaultHandle = new VaultHandle(
                new VaultTransport($this->config, $this->http, $this->staticSource),
                null
            );
        }
        return $this->defaultHandle;
    }

    /**
     * @param array{parentId:string,businessId?:string} $tenant
     */
    private static function describe(array $tenant): string
    {
        return 'parentId=' . $tenant['parentId']
            . (isset($tenant['businessId']) ? ', businessId=' . $tenant['businessId'] : '');
    }
}
