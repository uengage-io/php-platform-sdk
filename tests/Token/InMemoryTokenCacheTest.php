<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Token;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Token\InMemoryTokenCache;

class InMemoryTokenCacheTest extends TestCase
{
    public function testGetReturnsNullForUnknownKey(): void
    {
        $cache = new InMemoryTokenCache();
        $this->assertNull($cache->get('missing'));
    }

    public function testSetGetRoundTrip(): void
    {
        $cache = new InMemoryTokenCache();
        $cache->set('k', 'value', 60);
        $this->assertSame('value', $cache->get('k'));
    }

    public function testGetEvictsExpiredEntry(): void
    {
        $cache = new InMemoryTokenCache();
        // Set with ttl of -1 to simulate already-expired entry.
        $cache->set('k', 'value', -1);
        $this->assertNull($cache->get('k'));
    }

    public function testDeleteRemovesEntry(): void
    {
        $cache = new InMemoryTokenCache();
        $cache->set('k', 'v', 60);
        $cache->delete('k');
        $this->assertNull($cache->get('k'));
    }
}
