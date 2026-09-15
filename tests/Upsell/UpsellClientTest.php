<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Upsell;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;
use Uengage\PlatformSdk\Upsell\UpsellApiException;
use Uengage\PlatformSdk\Upsell\UpsellClient;

class UpsellClientTest extends TestCase
{
    /** @var StubHttp */
    private $stub;

    /** @var UpsellClient */
    private $upsell;

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
        $this->upsell = new UpsellClient($config, $signer);
    }

    private function samplePlacement(): array
    {
        return [
            'key' => 'cart_recommendation',
            'channel' => 'whitelabel_web',
            'touchpoint' => 'cart',
            'enabled' => true,
            'display' => [
                'title' => 'Frequently bought together',
                'maxItems' => 6,
                'layout' => 'horizontal_carousel',
            ],
            'configVersion' => 'seed-whitelabel_web-cart_recommendation',
        ];
    }

    private function sampleSuggestInput(): array
    {
        return [
            'channel' => 'whitelabel_web',
            'placement' => 'cart_recommendation',
            'tenant' => ['parentId' => 5, 'businessId' => 6],
            'context' => [
                'cart' => [
                    'subtotal' => 500,
                    'items' => [
                        [
                            'itemId' => 101,
                            'qty' => 1,
                            'unitPrice' => 400,
                            'itemSlug' => 'margherita',
                            'sectionName' => 'Pizzas',
                            'veg' => 1,
                        ],
                    ],
                ],
                'fulfilmentMode' => 'delivery',
                'sessionId' => 'sess-1',
            ],
        ];
    }

    // ─── placements ──────────────────────────────────────────────────────

    public function testPlacementsGetsTheChannelAndTenantQuery(): void
    {
        $this->stub->pushJson(200, ['placements' => [$this->samplePlacement()]]);

        $out = $this->upsell->placements([
            'channel' => 'whitelabel_web',
            'parentId' => 5,
            'businessId' => 6,
        ]);

        $this->assertCount(1, $out);
        $this->assertSame('cart_recommendation', $out[0]['key']);

        $call = $this->stub->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame(
            'https://api.test/v1/upsell/placements?channel=whitelabel_web&parentId=5&businessId=6',
            $call['url']
        );
    }

    public function testPlacementsUnwrapsAnEmptyListRatherThanFailing(): void
    {
        $this->stub->pushJson(200, ['placements' => []]);
        $this->assertSame([], $this->upsell->placements([
            'channel' => 'kiosk',
            'parentId' => 5,
            'businessId' => 6,
        ]));
    }

    public function testPlacementsRejectsNonPositiveIdsBeforeAnyRequest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive integer');
        $this->upsell->placements(['channel' => 'whitelabel_web', 'parentId' => 0, 'businessId' => 6]);
    }

    // ─── suggest ─────────────────────────────────────────────────────────

    public function testSuggestPostsTheFullContractBody(): void
    {
        $this->stub->pushJson(200, [
            'placement' => 'cart_recommendation',
            'touchpoint' => 'cart',
            'items' => [],
            'meta' => [
                'enabled' => true,
                'strategy' => 'menu-order-popularity',
                'configVersion' => 'v1',
                'rung' => 'full_engine',
                'maxItems' => 3,
                'ttlSeconds' => 900,
            ],
        ]);

        $input = $this->sampleSuggestInput();
        $out = $this->upsell->suggest($input);

        $this->assertSame('full_engine', $out['meta']['rung']);

        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.test/v1/upsell/suggestions', $call['url']);
        $this->assertSame($input, json_decode($call['body'], true));
    }

    /**
     * An empty list is a normal answer — a small cart legitimately has
     * nothing inside the price band. It must not be mistaken for an error.
     */
    public function testSuggestReturnsAnEmptyItemListWithoutThrowing(): void
    {
        $this->stub->pushJson(200, [
            'placement' => 'cart_recommendation',
            'touchpoint' => 'cart',
            'items' => [],
            'meta' => ['rung' => 'empty_degraded', 'maxItems' => 3],
        ]);

        $out = $this->upsell->suggest($this->sampleSuggestInput());
        $this->assertSame([], $out['items']);
        $this->assertSame('empty_degraded', $out['meta']['rung']);
    }

    public function testSuggestRejectsAMissingCartBeforeAnyRequest(): void
    {
        $input = $this->sampleSuggestInput();
        unset($input['context']['cart']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('context.cart.items');
        $this->upsell->suggest($input);
    }

    public function testSuggestAcceptsAnEmptyCartItemsArray(): void
    {
        $this->stub->pushJson(200, ['placement' => 'x', 'touchpoint' => 'cart', 'items' => [], 'meta' => []]);
        $input = $this->sampleSuggestInput();
        $input['context']['cart'] = ['subtotal' => 0, 'items' => []];
        $this->upsell->suggest($input);
        $this->assertSame('POST', $this->stub->lastCall()['method']);
    }

    public function testSuggestSurfacesA404AsUpsellApiException(): void
    {
        $this->stub->pushResponse(404, '{"error":"placement_not_found"}');

        try {
            $this->upsell->suggest($this->sampleSuggestInput());
            $this->fail('expected UpsellApiException');
        } catch (UpsellApiException $e) {
            $this->assertSame(404, $e->getStatus());
            $this->assertStringContainsString('placement_not_found', $e->getBody());
        }
    }

    // ─── reportEvents ────────────────────────────────────────────────────

    public function testReportEventsPostsTheBatch(): void
    {
        $this->stub->pushJson(202, ['accepted' => 2]);

        $out = $this->upsell->reportEvents([
            'channel' => 'whitelabel_web',
            'placement' => 'cart_recommendation',
            'tenant' => ['parentId' => 5, 'businessId' => 6],
            'sessionId' => 'sess-1',
            'configVersion' => 'v1',
            'events' => [
                ['type' => 'shown', 'itemId' => 981, 'reasonCode' => 'popular_now'],
                ['type' => 'added', 'itemId' => 981],
            ],
        ]);

        $this->assertSame(2, $out['accepted']);
        $this->assertSame('https://api.test/v1/upsell/events', $this->stub->lastCall()['url']);
    }

    public function testReportEventsRejectsAnEmptyBatchBeforeAnyRequest(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty array');
        $this->upsell->reportEvents([
            'channel' => 'whitelabel_web',
            'placement' => 'cart_recommendation',
            'tenant' => ['parentId' => 5, 'businessId' => 6],
            'events' => [],
        ]);
    }

    // ─── admin plane ─────────────────────────────────────────────────────

    public function testAdminListPlacementsOmitsParentIdForPlatformDefaults(): void
    {
        $this->stub->pushJson(200, ['placements' => []]);
        $this->upsell->adminListPlacements();
        $this->assertSame('https://api.test/v1/upsell/admin/placements', $this->stub->lastCall()['url']);
    }

    public function testAdminListPlacementsScopesToABrandWhenGiven(): void
    {
        $this->stub->pushJson(200, ['placements' => []]);
        $this->upsell->adminListPlacements(['parentId' => 5]);
        $this->assertSame(
            'https://api.test/v1/upsell/admin/placements?parentId=5',
            $this->stub->lastCall()['url']
        );
    }

    public function testAdminUpsertPlacementPutsAndReturnsTheSavedRecord(): void
    {
        $saved = array_merge($this->samplePlacement(), ['scope' => 'business', 'parentId' => 5, 'businessId' => 6]);
        $this->stub->pushJson(200, $saved);

        $out = $this->upsell->adminUpsertPlacement([
            'parentId' => 5,
            'businessId' => 6,
            'channel' => 'whitelabel_web',
            'key' => 'cart_recommendation',
            'touchpoint' => 'cart',
            'enabled' => true,
            'display' => ['title' => 'Complete your order', 'maxItems' => 6, 'layout' => 'grid_2xn_modal'],
        ]);

        $this->assertSame('business', $out['scope']);
        $call = $this->stub->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('https://api.test/v1/upsell/admin/placements', $call['url']);
    }

    public function testAdminUpsertPlacementRejectsBusinessIdWithoutParentId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('businessId requires parentId');
        $this->upsell->adminUpsertPlacement([
            'businessId' => 6,
            'channel' => 'whitelabel_web',
            'key' => 'cart_recommendation',
            'touchpoint' => 'cart',
            'enabled' => true,
            'display' => ['title' => 'x', 'maxItems' => 6, 'layout' => 'carousel_2up'],
        ]);
    }

    public function testAdminDeletePlacementResolvesOn204(): void
    {
        $this->stub->pushResponse(204, '');
        $this->upsell->adminDeletePlacement([
            'parentId' => 5,
            'channel' => 'whitelabel_web',
            'key' => 'cart_recommendation',
        ]);

        $call = $this->stub->lastCall();
        $this->assertSame('DELETE', $call['method']);
        $this->assertSame(
            'https://api.test/v1/upsell/admin/placements'
                . '?channel=whitelabel_web&key=cart_recommendation&parentId=5',
            $call['url']
        );
    }

    /**
     * Deleting a platform default removes the placement for every tenant
     * that has not overridden it, so the server requires the flag when
     * parentId is absent. Without it the call is a guaranteed 400.
     */
    public function testAdminDeletePlacementSendsConfirmDeleteDefaultWhenAsked(): void
    {
        $this->stub->pushResponse(204, '');
        $this->upsell->adminDeletePlacement([
            'channel' => 'whitelabel_web',
            'key' => 'cart_recommendation',
            'confirmDeleteDefault' => true,
        ]);

        $this->assertStringContainsString(
            'confirmDeleteDefault=true',
            $this->stub->lastCall()['url']
        );
    }

    /**
     * The flag must never be inferred. A caller who simply forgot parentId
     * has to receive the server's 400, not have the client decide that
     * wiping the default for every tenant was intended.
     */
    public function testAdminDeletePlacementOmitsConfirmDeleteDefaultUnlessTruthy(): void
    {
        $this->stub->pushResponse(204, '');
        $this->upsell->adminDeletePlacement([
            'channel' => 'whitelabel_web',
            'key' => 'cart_recommendation',
        ]);
        $this->assertStringNotContainsString('confirmDeleteDefault', $this->stub->lastCall()['url']);

        $this->stub->pushResponse(204, '');
        $this->upsell->adminDeletePlacement([
            'channel' => 'whitelabel_web',
            'key' => 'cart_recommendation',
            'confirmDeleteDefault' => false,
        ]);
        $this->assertStringNotContainsString('confirmDeleteDefault', $this->stub->lastCall()['url']);
    }

    // ─── review round 2 ──────────────────────────────────────────────────

    /**
     * Legacy callers hold ids as strings out of $_GET / $_SESSION / mysqli,
     * and the server coerces (`z.coerce.number()`), so rejecting them made
     * the client stricter than the API it wraps.
     */
    public function testIdsAreCoercedFromTheStringsLegacyCallersHold(): void
    {
        $this->stub->pushJson(200, ['placements' => []]);
        $this->upsell->placements(['channel' => 'whitelabel_web', 'parentId' => '5', 'businessId' => 6.0]);

        $this->assertStringContainsString('parentId=5', $this->stub->lastCall()['url']);
        $this->assertStringContainsString('businessId=6', $this->stub->lastCall()['url']);
    }

    /**
     * @dataProvider badIdProvider
     * @param mixed $bad
     */
    public function testCoercionStillRejectsWhatIsNotAnId($bad): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->upsell->placements(['channel' => 'whitelabel_web', 'parentId' => $bad, 'businessId' => 6]);
    }

    public function badIdProvider(): array
    {
        return [
            'fractional' => [5.5],
            'not numeric' => ['abc'],
            'zero' => [0],
            'negative' => [-1],
            'empty string' => [''],
            'null' => [null],
            'bool true' => [true],
            'array' => [[5]],
        ];
    }

    /**
     * array_filter is the obvious way to send only `added` events, and it
     * leaves a gapped array, which json_encode emits as a JSON OBJECT. The
     * server's z.array() rejects that with a 400 and the whole batch is
     * lost — silently, because this call fails open.
     */
    public function testGappedEventArraysAreReIndexedSoTheyEncodeAsAJsonArray(): void
    {
        $events = [
            ['type' => 'shown', 'itemId' => 1],
            ['type' => 'added', 'itemId' => 2],
        ];
        $onlyAdded = array_filter($events, function ($e) {
            return $e['type'] === 'added';
        });
        $this->assertSame([1], array_keys($onlyAdded), 'precondition: array_filter left a gap');

        $this->stub->pushJson(202, ['accepted' => 1]);
        $this->upsell->reportEvents([
            'channel' => 'whitelabel_web',
            'placement' => 'cart_recommendation',
            'tenant' => ['parentId' => 5, 'businessId' => 6],
            'events' => $onlyAdded,
        ]);

        $this->assertStringContainsString('"events":[{', $this->stub->lastCall()['body']);
        $this->assertStringNotContainsString('"events":{', $this->stub->lastCall()['body']);
    }

    public function testGappedCartItemsAreReIndexedToo(): void
    {
        $input = $this->sampleSuggestInput();
        $input['context']['cart']['items'] = [
            2 => ['itemId' => 3, 'qty' => 1, 'unitPrice' => 10],
        ];

        $this->stub->pushJson(200, ['items' => [], 'meta' => []]);
        $this->upsell->suggest($input);

        $this->assertStringContainsString('"items":[{', $this->stub->lastCall()['body']);
        $this->assertStringNotContainsString('"items":{', $this->stub->lastCall()['body']);
    }

    /**
     * json_encode returns false on unencodable input, and
     * RequestSigner::send() declares `?string $body` under strict_types —
     * so passing that false through was a fatal TypeError, not something a
     * caller could catch. INF fails on every PHP version, and the UTF-8
     * substitute retry does not apply to it.
     */
    public function testUnencodablePayloadThrowsCatchablyRatherThanFatally(): void
    {
        $input = $this->sampleSuggestInput();
        $input['context']['cart']['items'][0]['unitPrice'] = INF;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not encodable as JSON');
        $this->upsell->suggest($input);
    }

    /**
     * The docblock promises telemetry never reaches the cart. It used to
     * throw on every failure mode it had, while the README showed the call
     * with no try/catch.
     */
    public function testReportEventsFailsOpenOnAServerError(): void
    {
        $this->stub->pushResponse(500, '{"error":"boom"}');

        $out = $this->upsell->reportEvents([
            'channel' => 'whitelabel_web',
            'placement' => 'cart_recommendation',
            'tenant' => ['parentId' => 5, 'businessId' => 6],
            'events' => [['type' => 'shown', 'itemId' => 1]],
        ]);

        $this->assertSame(['accepted' => 0], $out);
    }

    public function testReportEventsFailsOpenOnAnUnencodablePayload(): void
    {
        $out = $this->upsell->reportEvents([
            'channel' => 'whitelabel_web',
            'placement' => 'cart_recommendation',
            'tenant' => ['parentId' => 5, 'businessId' => 6],
            'events' => [['type' => 'shown', 'itemId' => INF]],
        ]);

        $this->assertSame(['accepted' => 0], $out);
    }

    /** A caller bug still throws — it is not swallowed into a metric nobody reads. */
    public function testReportEventsStillThrowsOnCallerErrors(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 50 events');
        $this->upsell->reportEvents([
            'channel' => 'whitelabel_web',
            'placement' => 'cart_recommendation',
            'tenant' => ['parentId' => 5, 'businessId' => 6],
            'events' => array_fill(0, 51, ['type' => 'shown', 'itemId' => 1]),
        ]);
    }

    public function testSuggestRequiresACartSubtotal(): void
    {
        $input = $this->sampleSuggestInput();
        unset($input['context']['cart']['subtotal']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subtotal');
        $this->upsell->suggest($input);
    }

    /** 204 is the only success — a 200 carrying an error body is not. */
    public function testAdminDeletePlacementRejectsANon204Success(): void
    {
        $this->stub->pushResponse(200, '{"error":"nope"}');

        $this->expectException(UpsellApiException::class);
        $this->upsell->adminDeletePlacement([
            'parentId' => 5,
            'channel' => 'whitelabel_web',
            'key' => 'cart_recommendation',
        ]);
    }

    /**
     * A missing `placements` key is a broken response shape, not an empty
     * result — and callers are explicitly told empty is normal, so the two
     * must not look alike.
     */
    public function testPlacementsDistinguishesABrokenShapeFromAnEmptyResult(): void
    {
        $this->stub->pushJson(200, ['unexpected' => true]);

        $this->expectException(UpsellApiException::class);
        $this->expectExceptionMessage('missing the `placements` array');
        $this->upsell->placements(['channel' => 'whitelabel_web', 'parentId' => 5, 'businessId' => 6]);
    }

    public function testAdminDeletePlacementSurfacesA403AsUpsellApiException(): void
    {
        $this->stub->pushResponse(403, '{"error":"forbidden","message":"missing capability upsell.placements:write"}');

        try {
            $this->upsell->adminDeletePlacement([
                'parentId' => 5,
                'channel' => 'whitelabel_web',
                'key' => 'cart_recommendation',
            ]);
            $this->fail('expected UpsellApiException');
        } catch (UpsellApiException $e) {
            $this->assertSame(403, $e->getStatus());
        }
    }
}
