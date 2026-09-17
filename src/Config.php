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
 *   - the event-bus topic ARN / region / source (used by EventsClient,
 *     which talks to SNS directly rather than through the HTTP API)
 *
 * Per-namespace clients (ZonesClient, BusinessClient, ...) receive a
 * shared Config + HttpClient + RequestSigner triple at construction.
 */
class Config
{
    /**
     * Reported in the `User-Agent` header, so the gateway can attribute
     * traffic by SDK version.
     *
     * Hand-maintained, because composer takes the package version from the
     * git tag and there is no `version` field here to read. That made it
     * drift badly: the package was published at 1.0.0 while this constant
     * still said 0.1.0, so three releases misreported themselves. The
     * publish action now verifies this against the release tag and refuses
     * to publish on a mismatch, which is the only thing that can actually
     * enforce it — a unit test has no idea what tag it is being released
     * under.
     *
     * ALIGNED WITH THE NODE SDK, NOT LOCKED TO IT. The two packages track
     * the same platform API, and two numbers for one contract made "which
     * SDK version has the wallet passthroughs?" a question with two
     * answers — so from 2.1.0 onward the same surface carries the same
     * number in both. That is a target, not an invariant: Node is bumped
     * when its surface lands and this port follows in a later commit, so
     * the two legitimately sit apart while a port is in flight. During
     * that gap this constant reports what PHP actually implements, which
     * is the honest answer for a `User-Agent` and the reason nothing
     * enforces equality between the packages.
     *
     * The rule was briefly enforced as equality, by a unit test pinning
     * this constant to the Node package's `package.json`. It had to go:
     * the normal catch-up state failed it, and because the publish action
     * runs the suite before pushing the mirror, it blocked every PHP
     * release whenever Node was ahead.
     *
     * WHEN YOU BUMP THIS: match the Node number if you are landing the
     * port of a Node release, and take the next patch if you are not. Do
     * not re-sync to Node's number for its own sake — that would claim a
     * surface this package does not have.
     *
     * The alignment itself cost a version: this package went 1.1.0 → 2.1.0,
     * skipping 2.0.0, so the two would meet at the same number. That was a
     * major bump carrying no breaking change, with one real consequence —
     * composer constraints pinned `^1.x` did not resolve it, and consumers
     * had to widen to `^2.1` to pick it up.
     */
    const SDK_VERSION = '3.1.0';

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

    /** @var string|null */
    private $eventsTopicArn;

    /** @var string|null */
    private $eventsRegion;

    /** @var string */
    private $eventSource;

    public function __construct(
        string $baseUrl,
        string $authBaseUrl,
        string $customerAuthBaseUrl,
        ?TokenSourceInterface $tokenSource,
        ?string $actorVia = null,
        ?string $userAgent = null,
        ?string $eventsTopicArn = null,
        ?string $eventsRegion = null,
        ?string $eventSource = null
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authBaseUrl = rtrim($authBaseUrl, '/');
        $this->customerAuthBaseUrl = rtrim($customerAuthBaseUrl, '/');
        $this->tokenSource = $tokenSource;
        $this->actorVia = $actorVia;
        $this->userAgent = $userAgent !== null
            ? $userAgent
            : 'uengage-platform-sdk-php/' . self::SDK_VERSION;
        $this->eventsTopicArn = $eventsTopicArn;
        // Fall back to the region embedded in the topic ARN
        // (arn:aws:sns:<region>:<account>:<name>) so the common case needs
        // one setting, not two.
        $this->eventsRegion = $eventsRegion !== null
            ? $eventsRegion
            : self::regionFromArn($eventsTopicArn);
        $this->eventSource = $eventSource !== null && $eventSource !== ''
            ? $eventSource
            : 'legacy-php';
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

    /** SNS topic ARN of the platform event bus, or null when unconfigured. */
    public function getEventsTopicArn(): ?string
    {
        return $this->eventsTopicArn;
    }

    public function getEventsRegion(): ?string
    {
        return $this->eventsRegion;
    }

    /** Value stamped into the envelope's `source` field. */
    public function getEventSource(): string
    {
        return $this->eventSource;
    }

    /** `arn:aws:sns:<region>:<account>:<name>` -> `<region>`. */
    private static function regionFromArn(?string $arn): ?string
    {
        if ($arn === null || $arn === '') {
            return null;
        }
        $parts = explode(':', $arn);
        return isset($parts[3]) && $parts[3] !== '' ? $parts[3] : null;
    }
}
