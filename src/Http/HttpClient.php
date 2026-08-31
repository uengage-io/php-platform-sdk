<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Http;

use Uengage\PlatformSdk\Exceptions\ApiException;

/**
 * Thin cURL wrapper. Used by every namespace client.
 *
 * Why cURL directly (not Guzzle): the SDK's external dependency surface
 * stays at zero, matching the philosophy of `uengage.io/php-logger`.
 * cURL is available on every PHP install via ext-curl (a composer
 * require constraint).
 *
 * The client is stateless beyond `defaultBaseUrl` (saved at
 * construction). All per-request state - headers, body, method - is
 * supplied to send().
 */
class HttpClient
{
    /** @var string */
    private $defaultBaseUrl;

    /** @var int Timeout for the entire request, seconds. */
    private $timeoutSeconds;

    /** @var callable|null Test-seam: when set, `send` calls this instead of cURL. */
    private $fetchOverride;

    public function __construct(string $defaultBaseUrl, int $timeoutSeconds = 30)
    {
        $this->defaultBaseUrl = rtrim($defaultBaseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getDefaultBaseUrl(): string
    {
        return $this->defaultBaseUrl;
    }

    /**
     * Send an HTTP request.
     *
     * @param string $method GET, POST, PUT, PATCH, DELETE.
     * @param string $url Fully-qualified URL (caller builds base + path + query).
     * @param array<string, string> $headers Header name => value.
     * @param string|null $body Raw request body (JSON-encoded if applicable).
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if ($this->fetchOverride !== null) {
            return call_user_func($this->fetchOverride, $method, $url, $headers, $body);
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);

        $headerList = [];
        foreach ($headers as $name => $value) {
            $headerList[] = $name . ': ' . $value;
        }
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            // Some PHP/cURL combinations omit content-length on PATCH/PUT
            // unless we set it explicitly.
            $hasContentLength = false;
            foreach ($headers as $name => $_) {
                if (strtolower($name) === 'content-length') {
                    $hasContentLength = true;
                    break;
                }
            }
            if (!$hasContentLength) {
                $headerList[] = 'Content-Length: ' . strlen($body);
            }
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headerList);

        $responseHeaders = [];
        curl_setopt(
            $curl,
            CURLOPT_HEADERFUNCTION,
            function ($_, $header) use (&$responseHeaders) {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $name = strtolower(trim($parts[0]));
                    $value = trim($parts[1]);
                    $responseHeaders[$name] = $value;
                }
                return $len;
            }
        );

        $rawBody = curl_exec($curl);
        if ($rawBody === false) {
            $err = curl_error($curl);
            $errno = curl_errno($curl);
            curl_close($curl);
            throw new ApiException(
                'http',
                0,
                sprintf('cURL transport error (%d): %s', $errno, $err)
            );
        }
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return new HttpResponse($status, $responseHeaders, (string) $rawBody);
    }

    /**
     * Test-only: swap the cURL backend with a callable that receives
     * (method, url, headers, body) and returns an HttpResponse.
     */
    public function setFetchOverrideForTesting(?callable $override): void
    {
        $this->fetchOverride = $override;
    }
}
