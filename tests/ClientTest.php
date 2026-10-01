<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Alerts\AlertsClient;
use Uengage\PlatformSdk\Audit\AuditClient;
use Uengage\PlatformSdk\Auth\AuthClient;
use Uengage\PlatformSdk\Business\BusinessClient;
use Uengage\PlatformSdk\Client;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Http\HttpClient;
use Uengage\PlatformSdk\Tests\Support\StubSqsSender;
use Uengage\PlatformSdk\Token\InMemoryTokenCache;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;
use Uengage\PlatformSdk\Vault\VaultClient;
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
                'UENGAGE_ENV',
                'PLATFORM_ALERTS_QUEUE_URL',
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
        $this->assertInstanceOf(AlertsClient::class, $client->alerts);
    }

    public function testAlertsQueueUrlResolvesOptionThenEnvThenUengageEnv(): void
    {
        $custom = 'https://sqs.ap-south-1.amazonaws.com/1/custom.fifo';

        // Nothing configured: no queue (raise() will fail open at flush).
        $this->assertNull(Client::create(['baseUrl' => 'https://api.test'])->alerts->queueUrl());

        putenv('UENGAGE_ENV=uat');
        $this->assertSame(
            AlertsClient::ALERT_QUEUE_URLS['uat'],
            Client::create(['baseUrl' => 'https://api.test'])->alerts->queueUrl()
        );
        $this->assertSame(
            AlertsClient::ALERT_QUEUE_URLS['prod'],
            Client::create(['baseUrl' => 'https://api.test', 'env' => 'prod'])->alerts->queueUrl(),
            'the env option beats UENGAGE_ENV'
        );

        putenv('PLATFORM_ALERTS_QUEUE_URL=' . $custom);
        $this->assertSame($custom, Client::create(['baseUrl' => 'https://api.test', 'env' => 'prod'])->alerts->queueUrl());
        $this->assertSame(
            'https://sqs.ap-south-1.amazonaws.com/2/option.fifo',
            Client::create([
                'baseUrl' => 'https://api.test',
                'alertsQueueUrl' => 'https://sqs.ap-south-1.amazonaws.com/2/option.fifo',
            ])->alerts->queueUrl()
        );

        putenv('PLATFORM_ALERTS_QUEUE_URL=');
        putenv('UENGAGE_ENV=dev');
        $this->assertNull(
            Client::create(['baseUrl' => 'https://api.test'])->alerts->queueUrl(),
            'an unknown env has no default queue; empty env vars count as unset'
        );
    }

    public function testCreateInjectsTheSqsSender(): void
    {
        $sender = new StubSqsSender();
        $client = Client::create([
            'baseUrl' => 'https://api.test',
            'alertsQueueUrl' => 'https://sqs.ap-south-1.amazonaws.com/1/q.fifo',
            'sqsSender' => $sender,
        ]);
        $client->alerts->raise([
            'title' => 't',
            'description' => 'd',
            'tenant' => ['parentId' => 5, 'businessId' => 6],
            'type' => 'pg.invalid_credentials',
        ]);
        $client->alerts->flush();

        $this->assertCount(1, $sender->calls);
        $this->assertSame('https://sqs.ap-south-1.amazonaws.com/1/q.fifo', $sender->calls[0]['queueUrl']);
        $this->assertSame(0, $client->events->queueLength(), 'alerts no longer ride the event bus');
    }

    /**
     * The constructor's positional order is public API: `$vault` has been the
     * 5th argument since it shipped, so later additions go after it.
     */
    public function testConstructorKeepsVaultFifthAndSqsSenderSixth(): void
    {
        $config = new Config(
            'https://api.test',
            'https://api.test/auth/business',
            'https://api.test/auth/customer',
            null,
            null,
            null,
            null,
            null,
            null,
            'https://sqs.ap-south-1.amazonaws.com/1/q.fifo'
        );
        $http = new HttpClient('https://api.test');
        $vault = new VaultClient($config, $http);
        $sender = new StubSqsSender();

        $client = new Client($config, $http, null, null, $vault, $sender);

        $this->assertSame($vault, $client->vault);
        $client->alerts->raise([
            'title' => 't',
            'description' => 'd',
            'tenant' => ['parentId' => 5, 'businessId' => 6],
            'type' => 'pg.invalid_credentials',
        ]);
        $client->alerts->flush();
        $this->assertCount(1, $sender->calls, 'the 6th argument is the SQS sender');
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
