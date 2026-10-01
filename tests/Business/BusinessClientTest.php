<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Business;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Business\BusinessApiException;
use Uengage\PlatformSdk\Business\BusinessClient;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;

class BusinessClientTest extends TestCase
{
    /** @var StubHttp */
    private $stub;

    /** @var BusinessClient */
    private $business;

    protected function setUp(): void
    {
        $this->stub = new StubHttp();
        $config = new Config(
            'https://api.test',
            'https://api.test/auth/business',
            'https://api.test/auth/customer',
            new StaticBearerTokenSource('jwt')
        );
        $signer = new RequestSigner($config, $this->stub->client);
        $this->business = new BusinessClient($config, $signer);
    }

    public function testGetById(): void
    {
        $this->stub->pushJson(200, ['id' => 42, 'profile' => ['name' => 'Acme']]);
        $row = $this->business->get(42);
        $this->assertSame(42, $row['id']);
        $this->assertSame('https://api.test/v1/businesses/42', $this->stub->lastCall()['url']);
    }

    public function testGetRejectsNonPositiveId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->business->get(0);
    }

    public function testGetIncludesGroupsQueryWhenProvided(): void
    {
        $this->stub->pushJson(200, ['id' => 1]);
        $this->business->get(1, ['profile', 'compliance']);
        $this->assertSame(
            'https://api.test/v1/businesses/1?' . http_build_query(['groups' => 'profile,compliance']),
            $this->stub->lastCall()['url']
        );
    }

    public function testGetSurfaces404AsBusinessApiException(): void
    {
        $this->stub->pushJson(404, ['error' => 'not_found']);
        $this->expectException(BusinessApiException::class);
        $this->business->get(999);
    }

    public function testBulkRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->business->bulk([]);
    }

    public function testBulkRejectsOverCap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $ids = range(1, BusinessClient::BULK_MAX_IDS + 1);
        $this->business->bulk($ids);
    }

    public function testBulkRejectsNonPositiveIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->business->bulk([1, 0, 3]);
    }

    public function testBulkSendsCommaSeparatedIds(): void
    {
        $this->stub->pushJson(200, []);
        $this->business->bulk([1, 2, 3], ['profile']);
        $url = $this->stub->lastCall()['url'];
        $this->assertStringContainsString('ids=1%2C2%2C3', $url);
        $this->assertStringContainsString('groups=profile', $url);
    }
}
