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
     * Backend only: authorize the user's channel access before calling this method.
     * The registered client must have realtime.tokens:issue.
     * @return array {token: string, expiresIn: int, channel: string}
     */
    public function mintRealtimeToken(string $clientId, string $clientSecret, string $channel, int $expiresIn = 300, ?string $onBehalfOf = null): array
    {
        if ($clientId === '' || $clientSecret === '') {
            throw new InvalidArgumentException('Backend client credentials are required');
        }
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/', $channel)) {
            throw new InvalidArgumentException('Invalid channel name');
        }
        if ($expiresIn < 60 || $expiresIn > 900) {
            throw new InvalidArgumentException('expiresIn must be between 60 and 900 seconds');
        }
        if ($onBehalfOf !== null && !preg_match('/\A[\x21-\x7e]{1,128}\z/', $onBehalfOf)) {
            throw new InvalidArgumentException('onBehalfOf must be a printable ASCII identifier of 1-128 characters');
        }
        $response = $this->http->send('POST', $this->config->getAuthBaseUrl() . '/oauth/token', [
            'content-type' => 'application/x-www-form-urlencoded',
            'authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
        ], http_build_query([
            'grant_type' => 'urn:uengage:params:oauth:grant-type:realtime-channel',
            'channel' => $channel,
            'expires_in' => $expiresIn,
            'on_behalf_of' => $onBehalfOf,
        ]));
        if (!$response->isOk()) {
            throw new AuthApiException($response->getStatus(), $response->getBody());
        }
        $body = $response->json();
        if (!is_array($body) || !isset($body['access_token'], $body['expires_in'], $body['channel'])
            || !is_string($body['access_token']) || $body['access_token'] === ''
            || $body['channel'] !== $channel || !is_int($body['expires_in'])
            || $body['expires_in'] < 60 || $body['expires_in'] > $expiresIn) {
            throw new AuthApiException($response->getStatus(), 'Invalid realtime token response');
        }
        return ['token' => $body['access_token'], 'expiresIn' => $body['expires_in'], 'channel' => $channel];
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
