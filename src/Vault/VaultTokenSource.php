<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Uengage\PlatformSdk\Exceptions\AuthenticationException;
use Uengage\PlatformSdk\Http\HttpClient;
use Uengage\PlatformSdk\Token\TokenCacheInterface;
use Uengage\PlatformSdk\Token\TokenSourceInterface;

/**
 * Mints and caches vault tokens for ONE tenant from client credentials.
 *
 * `POST {authBaseUrl}/oauth/token` with
 * `grant_type=urn:uengage:params:oauth:grant-type:vault-business`, Basic
 * client authentication, `parent_id` and optional `business_id`. `scope` is
 * omitted unless given, so the token carries every vault scope the client's
 * registry entry allows.
 *
 * The token is cached in the client's TokenCacheInterface (APCu / file /
 * in-memory) per (auth surface, client, tenant, scopes) until 60 s before it
 * expires. `invalidate()` evicts it; the vault transport calls that on a 401
 * and retries once with a fresh mint.
 */
class VaultTokenSource implements TokenSourceInterface
{
    /** @var HttpClient */
    private $http;

    /** @var TokenCacheInterface */
    private $cache;

    /** @var string */
    private $authBaseUrl;

    /** @var string */
    private $clientId;

    /** @var string */
    private $clientSecret;

    /** @var array{parentId:string,businessId?:string} */
    private $tenant;

    /** @var array<int, string>|null */
    private $scopes;

    /** @var string */
    private $cacheKey;

    /**
     * @param array{parentId:string,businessId?:string} $tenant Already normalised.
     * @param array<int, string>|null $scopes
     */
    public function __construct(
        HttpClient $http,
        TokenCacheInterface $cache,
        string $authBaseUrl,
        string $clientId,
        #[\SensitiveParameter]
        string $clientSecret,
        array $tenant,
        ?array $scopes = null
    ) {
        $this->http = $http;
        $this->cache = $cache;
        $this->authBaseUrl = rtrim($authBaseUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->tenant = $tenant;
        $this->scopes = $scopes;
        $scopeKey = '';
        if ($scopes !== null) {
            $sorted = $scopes;
            sort($sorted);
            $scopeKey = ':' . sha1(implode(' ', $sorted));
        }
        $this->cacheKey = 'vault:' . sha1($this->authBaseUrl . '|' . $clientId) . ':'
            . $tenant['parentId'] . ':'
            . (isset($tenant['businessId']) ? $tenant['businessId'] : '-')
            . $scopeKey;
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function getAccessToken(): string
    {
        $cached = $this->cache->get($this->cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $minted = self::mint(
            $this->http,
            $this->authBaseUrl,
            $this->clientId,
            $this->clientSecret,
            $this->tenant,
            $this->scopes
        );
        $ttl = $minted['expiresIn'] - VaultGrant::EXPIRY_BUFFER_SECONDS;
        if ($ttl > 0) {
            $this->cache->set($this->cacheKey, $minted['accessToken'], $ttl);
        }
        return $minted['accessToken'];
    }

    public function invalidate(): void
    {
        $this->cache->delete($this->cacheKey);
    }

    /**
     * One vault-grant request. Never cached here; the instance method caches.
     *
     * @param array{parentId:string,businessId?:string} $tenant Already normalised.
     * @param array<int, string>|null $scopes
     * @return array{accessToken:string,expiresIn:int,scope:string}
     */
    public static function mint(
        HttpClient $http,
        string $authBaseUrl,
        string $clientId,
        #[\SensitiveParameter]
        string $clientSecret,
        array $tenant,
        ?array $scopes = null,
        ?int $expiresIn = null,
        ?string $onBehalfOf = null
    ): array {
        $form = [
            'grant_type' => VaultGrant::GRANT_TYPE,
            'parent_id' => $tenant['parentId'],
        ];
        if (isset($tenant['businessId'])) {
            $form['business_id'] = $tenant['businessId'];
        }
        if ($scopes !== null) {
            $form['scope'] = implode(' ', $scopes);
        }
        if ($expiresIn !== null) {
            $form['expires_in'] = (string) $expiresIn;
        }
        if ($onBehalfOf !== null && $onBehalfOf !== '') {
            $form['on_behalf_of'] = $onBehalfOf;
        }
        $response = $http->send(
            'POST',
            rtrim($authBaseUrl, '/') . '/oauth/token',
            [
                'content-type' => 'application/x-www-form-urlencoded',
                'authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
            ],
            http_build_query($form, '', '&', PHP_QUERY_RFC3986)
        );
        if (!$response->isOk()) {
            throw new AuthenticationException(sprintf(
                'vault token mint failed: HTTP %d %s',
                $response->getStatus(),
                $response->getBody()
            ));
        }
        $token = VaultGrant::checkTokenResponse($response->json(), $scopes, $expiresIn);
        if ($token === null) {
            throw new AuthenticationException('Invalid vault token response');
        }
        return $token;
    }
}
