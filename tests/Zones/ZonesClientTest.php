<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Zones;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;
use Uengage\PlatformSdk\Zones\ZonesApiException;
use Uengage\PlatformSdk\Zones\ZonesClient;

class ZonesClientTest extends TestCase
{
    const ZID = '11111111-2222-3333-4444-555555555555';

    /** @var StubHttp */
    private $stub;

    /** @var ZonesClient */
    private $zones;

    protected function setUp(): void
    {
        $this->stub = new StubHttp();
        $config = new Config(
            'https://api.test',
            'https://api.test/auth/business',
            'https://api.test/auth/customer',
            new StaticBearerTokenSource('test.jwt')
        );
        $signer = new RequestSigner($config, $this->stub->client);
        $this->zones = new ZonesClient($config, $signer);
    }

    private function samplePolygon(): array
    {
        return [
            'type' => 'Polygon',
            'coordinates' => [[[77.5, 12.9], [77.6, 12.9], [77.6, 13.0], [77.5, 13.0], [77.5, 12.9]]],
        ];
    }

    // ─── create ─────────────────────────────────────────────────────────

    public function testCreatePostsToZonesPath(): void
    {
        $this->stub->pushJson(201, ['zid' => self::ZID, 'tags' => [], 'createdAt' => '2026-05-24T00:00:00Z']);
        $result = $this->zones->create(['geometry' => $this->samplePolygon(), 'tags' => ['type' => 'delivery-area']]);

        $this->assertSame(self::ZID, $result['zid']);
        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.test/v1/zones', $call['url']);
        $this->assertStringContainsString('"type":"delivery-area"', $call['body']);
    }

    public function testCreateRejectsMissingGeometry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->create([]);
    }

    public function testCreateRejectsWrongGeometryType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->create(['geometry' => ['type' => 'Point', 'coordinates' => [0, 0]]]);
    }

    public function testCreateSurfaces4xxAsZonesApiException(): void
    {
        $this->stub->pushJson(400, ['error' => 'invalid_body']);
        $this->expectException(ZonesApiException::class);
        $this->expectExceptionMessage('zones API 400');
        $this->zones->create(['geometry' => $this->samplePolygon()]);
    }

    // ─── get ────────────────────────────────────────────────────────────

    public function testGetReturnsRow(): void
    {
        $this->stub->pushJson(200, ['zid' => self::ZID, 'tags' => [], 'geometry' => ['type' => 'MultiPolygon']]);
        $result = $this->zones->get(self::ZID);
        $this->assertSame(self::ZID, $result['zid']);
        $this->assertSame('https://api.test/v1/zones/' . self::ZID, $this->stub->lastCall()['url']);
    }

    public function testGetRejectsNonUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->get('not-a-uuid');
    }

    public function testGetSurfaces404(): void
    {
        $this->stub->pushJson(404, ['error' => 'not_found']);
        $this->expectException(ZonesApiException::class);
        $this->expectExceptionMessage('zones API 404');
        $this->zones->get(self::ZID);
    }

    // ─── update / replace / delete ──────────────────────────────────────

    public function testUpdateSendsPatch(): void
    {
        $this->stub->pushJson(200, ['zid' => self::ZID]);
        $this->zones->update(self::ZID, ['tags' => ['priority' => 'high', 'stale' => null]]);
        $call = $this->stub->lastCall();
        $this->assertSame('PATCH', $call['method']);
        $this->assertStringContainsString('"stale":null', $call['body']);
    }

    public function testReplaceSendsPut(): void
    {
        $this->stub->pushJson(200, ['zid' => self::ZID]);
        $this->zones->replace(self::ZID, ['geometry' => $this->samplePolygon(), 'tags' => []]);
        $this->assertSame('PUT', $this->stub->lastCall()['method']);
    }

    public function testDeleteHonours204(): void
    {
        $this->stub->pushResponse(204, '');
        $this->zones->delete(self::ZID); // no throw
        $this->assertSame('DELETE', $this->stub->lastCall()['method']);
    }

    public function testDeleteSurfaces404(): void
    {
        $this->stub->pushJson(404, ['error' => 'not_found']);
        $this->expectException(ZonesApiException::class);
        $this->zones->delete(self::ZID);
    }

    // ─── list ───────────────────────────────────────────────────────────

    public function testListPassesTagsAsJsonInQuery(): void
    {
        $this->stub->pushJson(200, ['zones' => [], 'nextCursor' => null]);
        $this->zones->list(['tags' => ['city' => ['BLR', 'BOM']], 'limit' => 25]);
        $url = $this->stub->lastCall()['url'];
        $this->assertStringContainsString('tags=' . urlencode('{"city":["BLR","BOM"]}'), $url);
        $this->assertStringContainsString('limit=25', $url);
    }

    public function testListRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->list(['limit' => 0]);
    }

    // ─── batch ──────────────────────────────────────────────────────────

    public function testCreateManyRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->createMany([]);
    }

    public function testCreateManyValidatesEachGeometry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->createMany([
            ['geometry' => $this->samplePolygon()],
            ['no-geometry-here' => true],
        ]);
    }

    public function testDeleteManyRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->deleteMany([]);
    }

    public function testDeleteManyValidatesEachUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->deleteMany([self::ZID, 'not-a-uuid']);
    }

    // ─── containing / contains ──────────────────────────────────────────

    public function testContainingRejectsMissingPoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->containing([]);
    }

    public function testContainingRejectsOutOfRangeLat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->containing(['point' => ['lat' => 91, 'lng' => 0]]);
    }

    public function testContainingRejectsBadIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->containing(['point' => ['lat' => 12.9, 'lng' => 77.5], 'ids' => ['nope']]);
    }

    public function testContainingReturnsZonesArray(): void
    {
        $this->stub->pushJson(200, ['zones' => [['zid' => self::ZID, 'tags' => []]]]);
        $result = $this->zones->containing(['point' => ['lat' => 12.97, 'lng' => 77.59]]);
        $this->assertCount(1, $result);
        $this->assertSame(self::ZID, $result[0]['zid']);
    }

    public function testContainsReturnsBoolean(): void
    {
        $this->stub->pushJson(200, ['zones' => [['zid' => self::ZID]]]);
        $this->assertTrue($this->zones->contains(self::ZID, ['lat' => 12.9, 'lng' => 77.5]));

        $this->stub->pushJson(200, ['zones' => []]);
        $this->assertFalse($this->zones->contains(self::ZID, ['lat' => 12.9, 'lng' => 77.5]));
    }

    // ─── nearby ─────────────────────────────────────────────────────────

    public function testNearbyRejectsMissingPoint(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->nearby(['radiusMeters' => 500]);
    }

    public function testNearbyRejectsNonPositiveRadius(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->nearby(['point' => ['lat' => 12.9, 'lng' => 77.5], 'radiusMeters' => 0]);
    }

    public function testNearbyRejectsBadIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->nearby(['point' => ['lat' => 12.9, 'lng' => 77.5], 'radiusMeters' => 500, 'ids' => ['nope']]);
    }

    public function testNearbyRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->nearby(['point' => ['lat' => 12.9, 'lng' => 77.5], 'radiusMeters' => 500, 'limit' => 0]);
    }

    public function testNearbyPostsAndReturnsZonesArray(): void
    {
        $this->stub->pushJson(200, ['zones' => [['zid' => self::ZID, 'tags' => [], 'distanceMeters' => 42.5]]]);
        $result = $this->zones->nearby(['point' => ['lat' => 12.97, 'lng' => 77.59], 'radiusMeters' => 500]);
        $this->assertCount(1, $result);
        $this->assertSame(self::ZID, $result[0]['zid']);
        $this->assertSame(42.5, $result[0]['distanceMeters']);

        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.test/v1/zones/nearby', $call['url']);
        $this->assertStringContainsString('"radiusMeters":500', $call['body']);
    }

    // ─── intersecting ───────────────────────────────────────────────────

    public function testIntersectingRejectsMissingGeometry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->intersecting([]);
    }

    public function testIntersectingRejectsWrongGeometryType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->intersecting(['geometry' => ['type' => 'Point', 'coordinates' => [0, 0]]]);
    }

    public function testIntersectingRejectsBadIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->intersecting(['geometry' => $this->samplePolygon(), 'ids' => ['nope']]);
    }

    public function testIntersectingRejectsBadExcludeZid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->zones->intersecting(['geometry' => $this->samplePolygon(), 'excludeZid' => 'not-a-uuid']);
    }

    public function testIntersectingPostsAndReturnsZonesArray(): void
    {
        $this->stub->pushJson(200, ['zones' => [['zid' => self::ZID, 'tags' => []]]]);
        $result = $this->zones->intersecting(['geometry' => $this->samplePolygon(), 'excludeZid' => self::ZID]);
        $this->assertCount(1, $result);
        $this->assertSame(self::ZID, $result[0]['zid']);

        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.test/v1/zones/intersecting', $call['url']);
    }

    public function testIntersectingReturnsEmptyWhenNoOverlap(): void
    {
        $this->stub->pushJson(200, ['zones' => []]);
        $result = $this->zones->intersecting(['geometry' => $this->samplePolygon()]);
        $this->assertCount(0, $result);
    }
}
