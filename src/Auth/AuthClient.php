<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Auth;

use InvalidArgumentException;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\HttpClient;

/**
 * Direct access to the platform auth service - covers the flows that
 * matter from PHP context:
 *
 *   - mintClientCredentialsToken(): one-shot mint without caching, for
 *     callers who want a Bearer without going through the SDK's
 *     token-source machinery (e.g. server-rendered auth pages).
 *
 *   - refreshAccessToken(): exchange a refresh_token for a new pair,
 *     using the same `(clientId)` the original login used.
 *
 *   - exchangeLegacySession(): legacy uEngage (id, token) -> JWT
 *     exchange, probing the business surface then falling back to
 *     customer. Same protocol as the JS SDK's `session` mode.
 *
 * Skipped in v0.1.0 (less common in server-side PHP contexts; add
 * when a consumer needs them): authorization_code + PKCE flow,
 * password grant, OTP verify, customer-side signup.
 *
 * This client uses HttpClient directly (no RequestSigner) because the
 * auth surface itself is unauthenticated for these flows - the
 * credentials live in the request body / Basic header, not a Bearer.
 */
class AuthClient
{
    /** @var Config */
    private $config;

    /** @var HttpClient */
    private $http;

    public function __construct(Config $config, HttpClient $http)
    {
        $this->config = $config;
        $this->http = $http;
    }

    /**
     * Mint a Bearer JWT via OAuth2 client_credentials.
     *
     * @return array {access_token: string, token_type: string, expires_in: int, scope?: string}
     */
    public function mintClientCredentialsToken(string $clientId, string $clientSecret, ?string $scope = null): array
    {
        if ($clientId === '' || $clientSecret === '') {
            throw new InvalidArgumentException('auth.mintClientCredentialsToken: clientId and clientSecret are required');
        }
        $body = 'grant_type=client_credentials';
        if ($scope !== null && $scope !== '') {
            $body .= '&scope=' . rawurlencode($scope);
        }
        $headers = [
            'content-type' => 'application/x-www-form-urlencoded',
            'authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ];
        $response = $this->http->send(
            'POST',
            $this->config->getAuthBaseUrl() . '/oauth/token',
            $headers,
            $body
        );
        if (!$response->isOk()) {
            throw new AuthApiException($response->getStatus(), $response->getBody());
        }
        $json = $response->json();
        if (!is_array($json) || !isset($json['access_token'])) {
            throw new AuthApiException(
                $response->getStatus(),
                sprintf('mint response missing access_token: %s', $response->getBody())
            );
        }
        return $json;
    }

    /**
     * Exchange a refresh_token for a fresh access+refresh pair.
     *
     * @return array {access_token, refresh_token, token_type, expires_in, scope?}
     */
    public function refreshAccessToken(string $refreshToken, string $clientId): array
    {
        if ($refreshToken === '' || $clientId === '') {
            throw new InvalidArgumentException('auth.refreshAccessToken: refreshToken and clientId are required');
        }
        $body = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
        ]);
        $headers = ['content-type' => 'application/x-www-form-urlencoded'];
        $response = $this->http->send(
            'POST',
            $this->config->getAuthBaseUrl() . '/oauth/token',
            $headers,
            $body
        );
        if (!$response->isOk()) {
            throw new AuthApiException($response->getStatus(), $response->getBody());
        }
        $json = $response->json();
        if (!is_array($json) || !isset($json['access_token'])) {
            throw new AuthApiException(
                $response->getStatus(),
                sprintf('refresh response missing access_token: %s', $response->getBody())
            );
        }
        return $json;
    }

    /**
     * Exchange a legacy uEngage `(id, token)` pair for a JWT. Probes the
     * business surface first; on `invalid_grant` (400), falls back to
     * the customer surface. Returns the body of whichever surface
     * accepted.
     *
     * @param string|int $sessionId
     * @return array {access_token, token_type, expires_in, ...}
     */
    public function exchangeLegacySession($sessionId, string $sessionToken): array
    {
        if ($sessionToken === '') {
            throw new InvalidArgumentException('auth.exchangeLegacySession: sessionToken is required');
        }
        $body = http_build_query([
            'grant_type' => 'legacy_session',
            'id' => (string) $sessionId,
            'token' => $sessionToken,
        ]);
        $headers = ['content-type' => 'application/x-www-form-urlencoded'];

        $businessResp = $this->http->send(
            'POST',
            $this->config->getAuthBaseUrl() . '/oauth/token',
            $headers,
            $body
        );
        if ($businessResp->isOk()) {
            return $businessResp->json();
        }
        if ($businessResp->getStatus() === 400) {
            $json = $businessResp->json();
            if (is_array($json) && isset($json['error']) && $json['error'] === 'invalid_grant') {
                $customerResp = $this->http->send(
                    'POST',
                    $this->config->getCustomerAuthBaseUrl() . '/oauth/token',
                    $headers,
                    $body
                );
                if ($customerResp->isOk()) {
                    return $customerResp->json();
                }
                throw new AuthApiException($customerResp->getStatus(), $customerResp->getBody());
            }
        }
        throw new AuthApiException($businessResp->getStatus(), $businessResp->getBody());
    }
}
