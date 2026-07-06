<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk;

use Uengage\PlatformSdk\Audit\AuditClient;
use Uengage\PlatformSdk\Auth\AuthClient;
use Uengage\PlatformSdk\Business\BusinessClient;
use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Http\HttpClient;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Token\CacheFactory;
use Uengage\PlatformSdk\Token\ClientCredentialsTokenSource;
use Uengage\PlatformSdk\Token\LegacySessionTokenSource;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;
use Uengage\PlatformSdk\Token\TokenCacheInterface;
use Uengage\PlatformSdk\Token\TokenSourceInterface;
use Uengage\PlatformSdk\Wallet\WalletClient;
use Uengage\PlatformSdk\Zones\ZonesClient;

/**
 * Top-level SDK entry point. Mirrors the JS SDK's `createClient(...)`
 * factory: one immutable client with `business`, `audit`, `auth`,
 * `zones`, and `wallet` namespaces hanging off it.
 *
 * Auth modes (mutually exclusive - pass exactly one):
 *
 *   - 'serviceId' + 'serviceSecret' : OAuth2 client_credentials. SDK
 *     mints + caches the JWT and refreshes on expiry / 401.
 *   - 'authToken' (string)          : static Bearer JWT. Caller owns
 *     freshness.
 *   - 'session' [id, token]         : legacy uEngage (id, token) pair.
 *     SDK probes business then customer surface.
 *
 * Zero modes is allowed - the client serves public endpoints (the
 * openapi spec, for example).
 *
 * Env defaults: when an option is omitted, UENGAGE_BASE_URL,
 * UENGAGE_AUTH_BASE_URL, UENGAGE_CUSTOMER_AUTH_BASE_URL,
 * UENGAGE_SERVICE_ID, UENGAGE_SERVICE_SECRET, UENGAGE_AUTH_TOKEN,
 * UENGAGE_SESSION_ID, UENGAGE_SESSION_TOKEN, UENGAGE_ACTOR_VIA are
 * read from getenv(). Explicit input always wins.
 */
class Client
{
    /** @var BusinessClient */
    public $business;

    /** @var AuditClient */
    public $audit;

    /** @var AuthClient */
    public $auth;

    /** @var ZonesClient */
    public $zones;

    /** @var WalletClient */
    public $wallet;

    /** @var Config */
    private $config;

    /** @var HttpClient */
    private $http;

    /** @var TokenSourceInterface|null */
    private $tokenSource;

    public function __construct(
        Config $config,
        HttpClient $http,
        ?TokenSourceInterface $tokenSource
    ) {
        $this->config = $config;
        $this->http = $http;
        $this->tokenSource = $tokenSource;
        $signer = new RequestSigner($config, $http);
        $this->business = new BusinessClient($config, $signer);
        $this->audit = new AuditClient($config, $signer);
        $this->auth = new AuthClient($config, $http);
        $this->zones = new ZonesClient($config, $signer);
        $this->wallet = new WalletClient($config, $signer);
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getTokenSource(): ?TokenSourceInterface
    {
        return $this->tokenSource;
    }

    /**
     * Build a fresh client from caller input + env defaults.
     *
     * @param array $input
     */
    public static function create(array $input = []): self
    {
        $baseUrl = self::pick($input, 'baseUrl', 'UENGAGE_BASE_URL', Config::DEFAULT_BASE_URL);
        $authBaseUrl = self::pick(
            $input,
            'authBaseUrl',
            'UENGAGE_AUTH_BASE_URL',
            $baseUrl . '/auth/business'
        );
        $customerAuthBaseUrl = self::pick(
            $input,
            'customerAuthBaseUrl',
            'UENGAGE_CUSTOMER_AUTH_BASE_URL',
            $baseUrl . '/auth/customer'
        );
        $actorVia = self::pickOptional($input, 'actorVia', 'UENGAGE_ACTOR_VIA');
        if ($actorVia === null) {
            $actorVia = self::pickOptional($input, 'serviceId', 'UENGAGE_SERVICE_ID');
        }

        $http = isset($input['http']) && $input['http'] instanceof HttpClient
            ? $input['http']
            : new HttpClient($baseUrl);
        $cache = isset($input['cache']) && $input['cache'] instanceof TokenCacheInterface
            ? $input['cache']
            : CacheFactory::preferred();

        $tokenSource = self::resolveTokenSource($input, $http, $cache, $authBaseUrl, $customerAuthBaseUrl);

        $config = new Config(
            $baseUrl,
            $authBaseUrl,
            $customerAuthBaseUrl,
            $tokenSource,
            $actorVia
        );

        return new self($config, $http, $tokenSource);
    }

    /**
     * Resolve exactly one auth mode from the merged input. Throws
     * ConfigException if more than one mode is provided.
     */
    private static function resolveTokenSource(
        array $input,
        HttpClient $http,
        TokenCacheInterface $cache,
        string $authBaseUrl,
        string $customerAuthBaseUrl
    ): ?TokenSourceInterface {
        $serviceId = self::pickOptional($input, 'serviceId', 'UENGAGE_SERVICE_ID');
        $serviceSecret = self::pickOptional($input, 'serviceSecret', 'UENGAGE_SERVICE_SECRET');
        $authToken = self::pickOptional($input, 'authToken', 'UENGAGE_AUTH_TOKEN');
        $session = isset($input['session']) && is_array($input['session']) ? $input['session'] : null;
        if ($session === null) {
            $envId = getenv('UENGAGE_SESSION_ID');
            $envToken = getenv('UENGAGE_SESSION_TOKEN');
            if ($envId !== false && $envToken !== false && $envId !== '' && $envToken !== '') {
                $session = ['id' => $envId, 'token' => $envToken];
            }
        }

        $modes = [];
        if ($serviceId !== null || $serviceSecret !== null) {
            $modes[] = 'serviceId+serviceSecret';
        }
        if ($authToken !== null) {
            $modes[] = 'authToken';
        }
        if ($session !== null) {
            $modes[] = 'session';
        }
        if (count($modes) > 1) {
            throw new ConfigException(sprintf(
                'Client::create: pick exactly one auth mode. Got %s. Use one of {serviceId, serviceSecret} | {authToken} | {session}.',
                implode(', ', $modes)
            ));
        }
        if (count($modes) === 0) {
            return null;
        }

        if ($authToken !== null) {
            return new StaticBearerTokenSource($authToken);
        }
        if ($session !== null) {
            if (!isset($session['id']) || !isset($session['token'])) {
                throw new ConfigException('Client::create: session must be {id, token}');
            }
            return new LegacySessionTokenSource(
                $http,
                $cache,
                $authBaseUrl,
                $customerAuthBaseUrl,
                (string) $session['id'],
                (string) $session['token']
            );
        }
        // serviceId+serviceSecret
        if ($serviceId === null || $serviceSecret === null) {
            throw new ConfigException(
                'Client::create: serviceId and serviceSecret must both be provided'
            );
        }
        $scope = self::pickOptional($input, 'scope', 'UENGAGE_SCOPE');
        return new ClientCredentialsTokenSource(
            $http,
            $cache,
            $authBaseUrl,
            $serviceId,
            $serviceSecret,
            $scope
        );
    }

    private static function pick(array $input, string $key, string $envKey, string $default): string
    {
        if (isset($input[$key]) && is_string($input[$key]) && $input[$key] !== '') {
            return $input[$key];
        }
        $env = getenv($envKey);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return $default;
    }

    private static function pickOptional(array $input, string $key, string $envKey): ?string
    {
        if (isset($input[$key]) && is_string($input[$key]) && $input[$key] !== '') {
            return $input[$key];
        }
        $env = getenv($envKey);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return null;
    }
}
