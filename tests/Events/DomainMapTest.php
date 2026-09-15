<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Events;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Events\EventsClient;

/**
 * Drift guard for the domain map.
 *
 * `packages/common/src/events/domains.json` is the single source of
 * truth. This package is published to Packagist and cannot reach into
 * the monorepo at runtime, so `EventsClient::domainMap()` keeps a copy —
 * and this test is what stops it rotting. Before it existed, adding
 * `rider => riders` on the TypeScript side and forgetting it here failed
 * nothing: the legacy publisher just started throwing
 * InvalidArgumentException at a call site.
 *
 * Skips (rather than fails) when the fixture is absent, so the suite
 * still runs against a standalone checkout of the published package,
 * where the monorepo is not there to read.
 */
class DomainMapTest extends TestCase
{
    private function fixturePath(): string
    {
        return __DIR__ . '/../../../common/src/events/domains.json';
    }

    /**
     * @return array<string, string>
     */
    private function canonicalMap(): array
    {
        $path = $this->fixturePath();
        if (!file_exists($path)) {
            $this->markTestSkipped(
                'domain map fixture not found at ' . $path
                . ' - expected when running outside the monorepo.'
            );
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'domains.json must decode to an object');
        return $decoded;
    }

    public function testDomainMapMatchesTheCanonicalFixture(): void
    {
        $canonical = $this->canonicalMap();
        $mine = EventsClient::domainMap();

        ksort($canonical);
        ksort($mine);

        $this->assertSame(
            $canonical,
            $mine,
            'EventsClient::domainMap() has drifted from packages/common/src/events/domains.json. '
            . 'Adding a bus domain means editing the JSON, the TS SDK, the realtime guard, and this map.'
        );
    }

    public function testEveryCanonicalResourceResolves(): void
    {
        foreach ($this->canonicalMap() as $resource => $domain) {
            $this->assertSame(
                $domain,
                EventsClient::domainForEventType($resource . '.created'),
                'resource ' . $resource . ' should resolve to domain ' . $domain
            );
        }
    }

    public function testUnlistedResourceDoesNotResolve(): void
    {
        $this->canonicalMap();
        $this->assertNull(EventsClient::domainForEventType('widget.created'));
    }
}
