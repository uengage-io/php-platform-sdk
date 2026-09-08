<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Http;

use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Token\TokenSourceInterface;

/**
 * Builds the headers every SDK request carries.
 *
 * Three platform-wide headers are always set:
 *
 *   - User-Agent (so the API gateway can attribute traffic by SDK version)
 *   - Idempotency-Key (UUID v4, on mutating methods only - so callers
 *     don't have to generate one per call)
 *   - content-type: application/json (when a body is supplied)
 *
 * Auth comes from `Config->getTokenSource()`. If the source is null
 * (caller chose to skip auth - public endpoints only), no Authorization
 * header is added.
 *
 * The retry-once-on-401 dance lives in `RequestSigner::sendWithAuthRetry`
 * so every namespace client gets the same behaviour.
 */
class RequestSigner
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
     * Compose the full URL for a path + optional query string.
     * `$query` should already include the leading `?` if non-empty.
     */
    public function url(string $path, string $query = ''): string
    {
        return $this->config->getBaseUrl() . $path . $query;
    }

    /**
     * Build headers and POST/GET/etc against the target URL. Retries
     * once on 401 if the token source supports invalidation.
     *
     * @param array<string, string> $extraHeaders
     */
    public function send(
        string $method,
        string $path,
        string $url,
        ?string $body = null,
        array $extraHeaders = []
    ): HttpResponse {
        $headers = $this->buildHeaders($method, $body, $extraHeaders);
        $response = $this->http->send($method, $url, $headers, $body);
        if ($response->getStatus() !== 401) {
            return $response;
        }
        $tokenSource = $this->config->getTokenSource();
        if ($tokenSource === null) {
            return $response;
        }
        // Invalidate cached token + retry once. If the source has no
        // notion of invalidation (static bearer), the retry will mint
        // the same token and fail again - acceptable, the caller sees
        // the 401.
        $tokenSource->invalidate();
        $headersRetry = $this->buildHeaders($method, $body, $extraHeaders);
        return $this->http->send($method, $url, $headersRetry, $body);
    }

    /**
     * @param array<string, string> $extraHeaders
     * @return array<string, string>
     */
    private function buildHeaders(string $method, ?string $body, array $extraHeaders): array
    {
        $headers = [
            'User-Agent' => $this->config->getUserAgent(),
        ];
        if ($body !== null) {
            $headers['content-type'] = 'application/json';
        }
        $methodUpper = strtoupper($method);
        if (in_array($methodUpper, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headers['idempotency-key'] = self::uuidV4();
        }
        $tokenSource = $this->config->getTokenSource();
        if ($tokenSource !== null) {
            $headers['authorization'] = 'Bearer ' . $tokenSource->getAccessToken();
        }
        foreach ($extraHeaders as $name => $value) {
            $headers[$name] = $value;
        }
        return $headers;
    }

    /**
     * RFC 4122 v4 UUID, generated via random_bytes (CSPRNG, available
     * on PHP 7.0+).
     */
    public static function uuidV4(): string
    {
        $data = random_bytes(16);
        // Set version (4) and variant (RFC 4122) bits.
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
