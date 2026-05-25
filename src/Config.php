<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk;

use Uengage\PlatformSdk\Token\TokenSourceInterface;

/**
 * Resolved per-client configuration. Built by `Client::create()` from
 * caller input + env defaults. Once instantiated, immutable.
 *
 * Holds:
 *   - the base URL the namespace clients send requests to
 *   - the active token source (may be null for fully-anonymous use)
 *   - the User-Agent header value
 *   - the audit `actorVia` (stamped into emitted events)
 *
 * Per-namespace clients (ZonesClient, BusinessClient, ...) receive a
 * shared Config + HttpClient + RequestSigner triple at construction.
 */
class Config
{
    const SDK_VERSION = '0.1.0';

    const DEFAULT_BASE_URL = 'https://api.platform.uengage.io';

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $authBaseUrl;

    /** @var string */
    private $customerAuthBaseUrl;

    /** @var TokenSourceInterface|null */
    private $tokenSource;

    /** @var string|null */
    private $actorVia;

    /** @var string */
    private $userAgent;

    public function __construct(
        string $baseUrl,
        string $authBaseUrl,
        string $customerAuthBaseUrl,
        ?TokenSourceInterface $tokenSource,
        ?string $actorVia = null,
        ?string $userAgent = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authBaseUrl = rtrim($authBaseUrl, '/');
        $this->customerAuthBaseUrl = rtrim($customerAuthBaseUrl, '/');
        $this->tokenSource = $tokenSource;
        $this->actorVia = $actorVia;
        $this->userAgent = $userAgent !== null
            ? $userAgent
            : 'uengage-platform-sdk-php/' . self::SDK_VERSION;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getAuthBaseUrl(): string
    {
        return $this->authBaseUrl;
    }

    public function getCustomerAuthBaseUrl(): string
    {
        return $this->customerAuthBaseUrl;
    }

    public function getTokenSource(): ?TokenSourceInterface
    {
        return $this->tokenSource;
    }

    public function getActorVia(): ?string
    {
        return $this->actorVia;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }
}
