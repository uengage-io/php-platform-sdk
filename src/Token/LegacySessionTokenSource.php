<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Token;

use Uengage\PlatformSdk\Exceptions\AuthenticationException;
use Uengage\PlatformSdk\Http\HttpClient;

/**
 * Legacy uEngage session-exchange token source.
 *
 * Mints a Bearer JWT via the `grant_type=legacy_session` flow. The
 * SDK probes the business surface first; if it returns `invalid_grant`,
 * it falls back to the customer surface. This matches the JS SDK's
 * behaviour and lets a caller carrying a legacy `(id, token)` pair
 * not need to know which side of the dashboard issued it.
 */
class LegacySessionTokenSource implements TokenSourceInterface
{
    /** @var HttpClient */
    private $http;

    /** @var TokenCacheInterface */
    private $cache;

    /** @var string */
    private $businessAuthBaseUrl;

    /** @var string */
    private $customerAuthBaseUrl;

    /** @var string */
    private $sessionId;

    /** @var string */
    private $sessionToken;

    /** @var int */
    private $expiryBufferSeconds;

    /** @var string */
    private $cacheKey;

    public function __construct(
        HttpClient $http,
        TokenCacheInterface $cache,
        string $businessAuthBaseUrl,
        string $customerAuthBaseUrl,
        string $sessionId,
        string $sessionToken,
        int $expiryBufferSeconds = 30
    ) {
        $this->http = $http;
        $this->cache = $cache;
        $this->businessAuthBaseUrl = rtrim($businessAuthBaseUrl, '/');
        $this->customerAuthBaseUrl = rtrim($customerAuthBaseUrl, '/');
        $this->sessionId = $sessionId;
        $this->sessionToken = $sessionToken;
        $this->expiryBufferSeconds = $expiryBufferSeconds;
        $this->cacheKey = 'legacy_session:' . sha1(
            $businessAuthBaseUrl . '|' . $sessionId
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
        $body = http_build_query([
            'grant_type' => 'legacy_session',
            'id' => $this->sessionId,
            'token' => $this->sessionToken,
        ]);
        $headers = ['content-type' => 'application/x-www-form-urlencoded'];

        $businessResp = $this->http->send(
            'POST',
            $this->businessAuthBaseUrl . '/oauth/token',
            $headers,
            $body
        );
        if ($businessResp->isOk()) {
            return $this->extractAndCache($businessResp);
        }
        if ($this->isInvalidGrant($businessResp)) {
            $customerResp = $this->http->send(
                'POST',
                $this->customerAuthBaseUrl . '/oauth/token',
                $headers,
                $body
            );
            if ($customerResp->isOk()) {
                return $this->extractAndCache($customerResp);
            }
            throw new AuthenticationException(sprintf(
                'legacy_session exchange failed on both surfaces: customer HTTP %d %s',
                $customerResp->getStatus(),
                $customerResp->getBody()
            ));
        }
        throw new AuthenticationException(sprintf(
            'legacy_session exchange failed: HTTP %d %s',
            $businessResp->getStatus(),
            $businessResp->getBody()
        ));
    }

    private function isInvalidGrant(\Uengage\PlatformSdk\Http\HttpResponse $response): bool
    {
        if ($response->getStatus() !== 400) {
            return false;
        }
        $json = $response->json();
        return is_array($json) && isset($json['error']) && $json['error'] === 'invalid_grant';
    }

    private function extractAndCache(\Uengage\PlatformSdk\Http\HttpResponse $response): string
    {
        $json = $response->json();
        if (!is_array($json) || empty($json['access_token']) || !isset($json['expires_in'])) {
            throw new AuthenticationException(
                'legacy_session response missing access_token / expires_in'
            );
        }
        $accessToken = (string) $json['access_token'];
        $expiresIn = max(1, (int) $json['expires_in'] - $this->expiryBufferSeconds);
        $this->cache->set($this->cacheKey, $accessToken, $expiresIn);
        return $accessToken;
    }
}
