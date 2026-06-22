<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Token;

use Uengage\PlatformSdk\Exceptions\AuthenticationException;
use Uengage\PlatformSdk\Http\HttpClient;

/**
 * OAuth2 client_credentials token source.
 *
 * Mints a Bearer JWT via `POST $authBaseUrl/oauth/token` using HTTP
 * Basic auth with `(clientId, clientSecret)`. The minted token is
 * cached via the provided `TokenCacheInterface` so subsequent requests
 * (within the token's TTL) reuse it instead of hitting the auth
 * surface every time.
 *
 * `expiryBufferSeconds` (default 30s) is subtracted from the server-
 * reported `expires_in` so we refresh slightly before the actual
 * expiry; eliminates the in-flight 401 race.
 */
class ClientCredentialsTokenSource implements TokenSourceInterface
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

    /** @var string|null Space-separated scope list, or null for default. */
    private $scope;

    /** @var int */
    private $expiryBufferSeconds;

    /** @var string Cache key derived from constructor args. */
    private $cacheKey;

    public function __construct(
        HttpClient $http,
        TokenCacheInterface $cache,
        string $authBaseUrl,
        string $clientId,
        string $clientSecret,
        ?string $scope = null,
        int $expiryBufferSeconds = 30
    ) {
        $this->http = $http;
        $this->cache = $cache;
        $this->authBaseUrl = rtrim($authBaseUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->scope = $scope;
        $this->expiryBufferSeconds = $expiryBufferSeconds;
        $this->cacheKey = 'client_credentials:' . sha1(
            $authBaseUrl . '|' . $clientId . '|' . ($scope ?? '')
        );
    }

    public function getAccessToken(): string
    {
        $cached = $this->cache->get($this->cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        return $this->mintAndCache();
    }

    public function invalidate(): void
    {
        $this->cache->delete($this->cacheKey);
    }

    private function mintAndCache(): string
    {
        $body = 'grant_type=client_credentials';
        if ($this->scope !== null && $this->scope !== '') {
            $body .= '&scope=' . rawurlencode($this->scope);
        }
        $headers = [
            'content-type' => 'application/x-www-form-urlencoded',
            'authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
        ];
        $response = $this->http->send(
            'POST',
            $this->authBaseUrl . '/oauth/token',
            $headers,
            $body
        );
        if (!$response->isOk()) {
            throw new AuthenticationException(sprintf(
                'client_credentials mint failed: HTTP %d %s',
                $response->getStatus(),
                $response->getBody()
            ));
        }
        $json = $response->json();
        if (!is_array($json) || empty($json['access_token']) || !isset($json['expires_in'])) {
            throw new AuthenticationException(
                'client_credentials response missing access_token / expires_in'
            );
        }
        $accessToken = (string) $json['access_token'];
        $expiresIn = max(1, (int) $json['expires_in'] - $this->expiryBufferSeconds);
        $this->cache->set($this->cacheKey, $accessToken, $expiresIn);
        return $accessToken;
    }
}
