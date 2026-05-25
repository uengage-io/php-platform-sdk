<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Audit\AuditClient;
use Uengage\PlatformSdk\Auth\AuthClient;
use Uengage\PlatformSdk\Business\BusinessClient;
use Uengage\PlatformSdk\Client;
use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Http\HttpClient;
use Uengage\PlatformSdk\Token\InMemoryTokenCache;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;
use Uengage\PlatformSdk\Zones\ZonesClient;

class ClientTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset env between tests so leaks don't fail cases.
        foreach (
            [
                'UENGAGE_BASE_URL',
                'UENGAGE_AUTH_BASE_URL',
                'UENGAGE_CUSTOMER_AUTH_BASE_URL',
                'UENGAGE_SERVICE_ID',
                'UENGAGE_SERVICE_SECRET',
                'UENGAGE_AUTH_TOKEN',
                'UENGAGE_SESSION_ID',
                'UENGAGE_SESSION_TOKEN',
                'UENGAGE_ACTOR_VIA',
                'UENGAGE_SCOPE',
            ] as $key
        ) {
            putenv($key);
        }
    }

    public function testCreateWiresAllFourNamespaces(): void
    {
        $client = Client::create([
            'baseUrl' => 'https://api.test',
            'authToken' => 'static.jwt',
            'cache' => new InMemoryTokenCache(),
            'http' => new HttpClient('https://api.test'),
        ]);
        $this->assertInstanceOf(ZonesClient::class, $client->zones);
        $this->assertInstanceOf(BusinessClient::class, $client->business);
        $this->assertInstanceOf(AuditClient::class, $client->audit);
        $this->assertInstanceOf(AuthClient::class, $client->auth);
    }

    public function testCreateWithAuthTokenUsesStaticBearerSource(): void
    {
        $client = Client::create([
            'baseUrl' => 'https://api.test',
            'authToken' => 'static.jwt',
            'cache' => new InMemoryTokenCache(),
        ]);
        $tokenSource = $client->getTokenSource();
        $this->assertInstanceOf(StaticBearerTokenSource::class, $tokenSource);
        $this->assertSame('static.jwt', $tokenSource->getAccessToken());
    }

    public function testCreateWithoutAuthIsAllowed(): void
    {
        $client = Client::create(['baseUrl' => 'https://api.test']);
        $this->assertNull($client->getTokenSource());
    }

    public function testCreateRejectsMultipleAuthModes(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('pick exactly one auth mode');
        Client::create([
            'baseUrl' => 'https://api.test',
            'authToken' => 'jwt',
            'serviceId' => 'cid',
            'serviceSecret' => 'sec',
        ]);
    }

    public function testCreateFromEnvDefaults(): void
    {
        putenv('UENGAGE_BASE_URL=https://api.test');
        putenv('UENGAGE_AUTH_TOKEN=env-jwt');
        $client = Client::create();
        $tokenSource = $client->getTokenSource();
        $this->assertInstanceOf(StaticBearerTokenSource::class, $tokenSource);
        $this->assertSame('env-jwt', $tokenSource->getAccessToken());
        $this->assertSame('https://api.test', $client->getConfig()->getBaseUrl());
    }

    public function testExplicitInputOverridesEnv(): void
    {
        putenv('UENGAGE_AUTH_TOKEN=env-jwt');
        $client = Client::create(['authToken' => 'explicit-jwt']);
        $this->assertSame('explicit-jwt', $client->getTokenSource()->getAccessToken());
    }
}
