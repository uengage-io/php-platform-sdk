<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Http;

/**
 * Minimal HTTP response value object. Returned by HttpClient::send().
 * Decoded JSON access lives on the per-namespace clients; this class
 * is intentionally narrow (status + headers + raw body).
 */
class HttpResponse
{
    /** @var int */
    private $status;

    /** @var array<string, string> */
    private $headers;

    /** @var string */
    private $body;

    /**
     * @param array<string, string> $headers Lower-cased header names.
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function getHeader(string $name): ?string
    {
        $lower = strtolower($name);
        return isset($this->headers[$lower]) ? $this->headers[$lower] : null;
    }

    /**
     * Decode the body as JSON. Returns null on parse failure.
     * @return mixed
     */
    public function json()
    {
        $decoded = json_decode($this->body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
