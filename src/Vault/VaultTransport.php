<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Exceptions\ApiException;
use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Http\HttpClient;
use Uengage\PlatformSdk\Http\HttpResponse;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Token\TokenSourceInterface;

/**
 * @internal Sends one vault request: bearer from the handle's token source,
 * one retry after a 401 when the source can produce a different token, and
 * non-2xx mapped to {@see VaultApiException} subclasses.
 */
class VaultTransport
{
    /** @var Config */
    private $config;

    /** @var HttpClient */
    private $http;

    /** @var TokenSourceInterface|null */
    private $tokenSource;

    public function __construct(Config $config, HttpClient $http, ?TokenSourceInterface $tokenSource)
    {
        $this->config = $config;
        $this->http = $http;
        $this->tokenSource = $tokenSource;
    }

    /**
     * @param array<string, mixed>|object|null $body JSON body, or null for none.
     * @return mixed Decoded JSON (null for an empty body).
     */
    public function request(string $method, string $path, string $query = '', $body = null)
    {
        $encoded = null;
        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new ConfigException('vault: request body is not JSON-encodable: ' . json_last_error_msg());
            }
        }
        $url = $this->config->getBaseUrl() . '/v1/vault' . $path . $query;

        $response = $this->send($method, $url, $encoded);
        if ($response->getStatus() === 401 && $this->canRetry()) {
            $this->tokenSource->invalidate();
            $response = $this->send($method, $url, $encoded);
        }
        if (!$response->isOk()) {
            throw VaultApiException::fromResponse($response->getStatus(), $response->getBody());
        }
        if ($response->getBody() === '') {
            return null;
        }
        $json = $response->json();
        if ($json === null) {
            throw new VaultUnavailableException($response->getStatus(), 'vault: response was not JSON');
        }
        return $json;
    }

    /**
     * Presigned S3 POST: multipart, fields first, then `file`. No
     * Authorization header - the policy in `fields` is the authorisation.
     *
     * @param array<string, string> $fields
     * @param string $documentId Named in the error when storage refuses.
     */
    public function uploadMultipart(
        string $url,
        array $fields,
        string $contents,
        string $contentType,
        string $filename,
        string $documentId
    ): void {
        $boundary = '----uengage-vault-' . bin2hex(random_bytes(12));
        $parts = '';
        foreach ($fields as $name => $value) {
            $parts .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . self::quote((string) $name) . "\"\r\n\r\n"
                . (string) $value . "\r\n";
        }
        $parts .= '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="file"; filename="' . self::quote($filename) . "\"\r\n"
            . 'Content-Type: ' . $contentType . "\r\n\r\n"
            . $contents . "\r\n"
            . '--' . $boundary . "--\r\n";
        try {
            $response = $this->http->send('POST', $url, [
                'User-Agent' => $this->config->getUserAgent(),
                'content-type' => 'multipart/form-data; boundary=' . $boundary,
            ], $parts);
        } catch (ApiException $e) {
            throw VaultApiException::uploadFailed(0, $e->getMessage(), $documentId);
        }
        if (!$response->isOk()) {
            throw VaultApiException::uploadFailed($response->getStatus(), $response->getBody(), $documentId);
        }
    }

    private function canRetry(): bool
    {
        if ($this->tokenSource === null) {
            return false;
        }
        if ($this->tokenSource instanceof VaultStaticTokenSource) {
            return $this->tokenSource->canRefresh();
        }
        return true;
    }

    private function send(string $method, string $url, ?string $body): HttpResponse
    {
        $headers = ['User-Agent' => $this->config->getUserAgent(), 'accept' => 'application/json'];
        if ($body !== null) {
            $headers['content-type'] = 'application/json';
        }
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $headers['idempotency-key'] = RequestSigner::uuidV4();
        }
        if ($this->tokenSource === null) {
            throw new ConfigException(
                'vault: no auth configured. Pass serviceId + serviceSecret, authToken, or getToken to Client::create().'
            );
        }
        $headers['authorization'] = 'Bearer ' . $this->tokenSource->getAccessToken();
        try {
            return $this->http->send($method, $url, $headers, $body);
        } catch (ApiException $e) {
            // cURL transport failure: no response at all.
            throw new VaultUnavailableException(0, $e->getMessage());
        }
    }

    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }
}
