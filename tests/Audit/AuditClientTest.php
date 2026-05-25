<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Audit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Audit\AuditClient;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Exceptions\ConfigException;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;

class AuditClientTest extends TestCase
{
    private function makeClient(?string $actorVia = 'test-service', ?StubHttp $stub = null): array
    {
        $stub = $stub ?: new StubHttp();
        $config = new Config(
            'https://api.test',
            'https://api.test/auth/business',
            'https://api.test/auth/customer',
            new StaticBearerTokenSource('jwt'),
            $actorVia
        );
        $signer = new RequestSigner($config, $stub->client);
        // maxBatchSize=999 so a single record() doesn't auto-flush in tests.
        return [$stub, new AuditClient($config, $signer, 999)];
    }

    private function validEvent(): array
    {
        return [
            'event_type' => 'business.profile_updated',
            'tenant' => ['id' => 't-1', 'parent_id' => null],
            'actor' => ['type' => 'service', 'id' => 'svc'],
            'resource' => ['type' => 'business', 'id' => '42'],
            'changes' => [],
        ];
    }

    public function testRecordRequiresActorViaOnConfig(): void
    {
        [, $client] = $this->makeClient(null);
        $this->expectException(ConfigException::class);
        $client->record($this->validEvent());
    }

    public function testRecordRequiresEventType(): void
    {
        [, $client] = $this->makeClient();
        $event = $this->validEvent();
        unset($event['event_type']);
        $this->expectException(InvalidArgumentException::class);
        $client->record($event);
    }

    public function testRecordRequiresActor(): void
    {
        [, $client] = $this->makeClient();
        $event = $this->validEvent();
        unset($event['actor']);
        $this->expectException(InvalidArgumentException::class);
        $client->record($event);
    }

    public function testRecordEnqueuesAndStampsMetadata(): void
    {
        [, $client] = $this->makeClient();
        $client->record($this->validEvent());
        $this->assertSame(1, $client->queueLength());
    }

    public function testFlushPostsBatchAndClearsQueue(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(202, []);
        $client->record($this->validEvent());
        $client->record($this->validEvent());
        $this->assertSame(2, $client->queueLength());

        $client->flush();
        $this->assertSame(0, $client->queueLength());

        $call = $stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.test/v1/audit/events', $call['url']);
        $payload = json_decode($call['body'], true);
        $this->assertCount(2, $payload['events']);
        foreach ($payload['events'] as $e) {
            $this->assertSame('test-service', $e['actor']['via']);
            $this->assertNotEmpty($e['event_id']);
            $this->assertNotEmpty($e['occurred_at']);
            // ULID: 26 chars, Crockford base32
            $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $e['event_id']);
            // ISO 8601 Z, no millis (matches JS SDK shape)
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $e['occurred_at']);
        }
    }

    public function testFlushIsNoopOnEmptyQueue(): void
    {
        [$stub, $client] = $this->makeClient();
        $client->flush();
        $this->assertSame(0, count($stub->getCalls()));
    }

    public function testFlushRetriesOn5xx(): void
    {
        [$stub, $client] = $this->makeClient();
        $stub->pushJson(503, ['error' => 'unavailable']);
        $stub->pushJson(202, []);
        $client->record($this->validEvent());
        $client->flush();
        $this->assertCount(2, $stub->getCalls());
    }

    public function testUlidsAreLexicographicallySortable(): void
    {
        $first = AuditClient::generateUlid();
        usleep(2000);
        $second = AuditClient::generateUlid();
        // Time-bytes prefix means later ULIDs sort >= earlier ones.
        $this->assertGreaterThanOrEqual(0, strcmp($second, $first));
    }
}
