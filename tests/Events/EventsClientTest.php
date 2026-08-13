<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Events;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Events\EventsClient;
use Uengage\PlatformSdk\Support\Ulid;
use Uengage\PlatformSdk\Tests\Support\StubSnsPublisher;

class EventsClientTest extends TestCase
{
    const TOPIC_ARN = 'arn:aws:sns:ap-south-1:822426641710:platform-events-dev';

    /**
     * @return array{0: StubSnsPublisher, 1: EventsClient}
     */
    private function makeClient(?string $topicArn = self::TOPIC_ARN): array
    {
        $publisher = new StubSnsPublisher();
        $config = new Config(
            'https://api.test',
            'https://api.test/auth/business',
            'https://api.test/auth/customer',
            null,
            'test-service',
            null,
            $topicArn,
            null,
            'legacy-php'
        );
        return [$publisher, new EventsClient($config, $publisher)];
    }

    public function testPublishBuildsTheEnvelope(): void
    {
        [$publisher, $client] = $this->makeClient();

        $id = $client->publish(
            'order.status_changed',
            ['orderId' => '9034812', 'statusRank' => 40],
            ['tenantId' => '38112']
        );
        $client->flush();

        $envelopes = $publisher->allEnvelopes();
        $this->assertCount(1, $envelopes);
        $envelope = $envelopes[0];

        $this->assertSame($id, $envelope['id']);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $envelope['id']);
        $this->assertSame('order.status_changed', $envelope['type']);
        $this->assertSame('orders', $envelope['domain']);
        $this->assertSame('legacy-php', $envelope['source']);
        $this->assertSame(1, $envelope['version']);
        $this->assertSame('38112', $envelope['tenantId']);
        $this->assertSame(['orderId' => '9034812', 'statusRank' => 40], $envelope['data']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            $envelope['occurredAt']
        );
    }

    public function testPublishBuffersUntilFlush(): void
    {
        [$publisher, $client] = $this->makeClient();

        $client->publish('order.created', ['orderId' => '1'], ['tenantId' => '38112']);
        $client->publish('order.created', ['orderId' => '2'], ['tenantId' => '38112']);

        $this->assertSame(2, $client->queueLength());
        $this->assertCount(0, $publisher->calls);

        $client->flush();
        $this->assertSame(0, $client->queueLength());
        $this->assertCount(1, $publisher->calls);
    }

    public function testFlushChunksAtTenPerCall(): void
    {
        [$publisher, $client] = $this->makeClient();

        for ($i = 0; $i < 23; $i++) {
            $client->publish('order.created', ['n' => $i], ['tenantId' => '38112']);
        }
        $client->flush();

        $this->assertCount(3, $publisher->calls);
        $this->assertCount(10, $publisher->calls[0]['envelopes']);
        $this->assertCount(10, $publisher->calls[1]['envelopes']);
        $this->assertCount(3, $publisher->calls[2]['envelopes']);
        $this->assertSame(self::TOPIC_ARN, $publisher->calls[0]['topicArn']);
    }

    public function testIdsAreMonotonicWithinAProcess(): void
    {
        [$publisher, $client] = $this->makeClient();

        for ($i = 0; $i < 40; $i++) {
            $client->publish('order.created', ['n' => $i], ['tenantId' => '38112']);
        }
        $client->flush();

        $ids = array_column($publisher->allEnvelopes(), 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids, 'envelope ids must sort in emission order');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function testFlushFailsOpenWhenTheTransportThrows(): void
    {
        [$publisher, $client] = $this->makeClient();
        $publisher->throwable = new RuntimeException('SNS unreachable');

        $client->publish('order.created', ['orderId' => '1'], ['tenantId' => '38112']);
        $client->flush();

        // No exception escaped, the queue drained, and the failure counted.
        $this->assertSame(0, $client->queueLength());
        $this->assertSame(1, $client->failedCount());
    }

    public function testFlushFailsOpenWhenNoTopicIsConfigured(): void
    {
        [$publisher, $client] = $this->makeClient(null);

        $client->publish('order.created', ['orderId' => '1'], ['tenantId' => '38112']);
        $client->flush();

        $this->assertCount(0, $publisher->calls);
        $this->assertSame(1, $client->failedCount());
    }

    public function testFlushCountsPerEntryRejections(): void
    {
        [$publisher, $client] = $this->makeClient();
        $publisher->failAll = true;

        $client->publish('order.created', ['orderId' => '1'], ['tenantId' => '38112']);
        $client->publish('order.created', ['orderId' => '2'], ['tenantId' => '38112']);
        $client->flush();

        $this->assertSame(2, $client->failedCount());
    }

    public function testFlushIsANoOpWhenNothingIsBuffered(): void
    {
        [$publisher, $client] = $this->makeClient();
        $client->flush();
        $this->assertCount(0, $publisher->calls);
    }

    public function testPublishRejectsAnUndottedType(): void
    {
        [, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->publish('OrderCreated', [], ['tenantId' => '38112']);
    }

    public function testPublishRequiresATenantId(): void
    {
        [, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->publish('order.created', ['orderId' => '1'], []);
    }

    public function testPublishRejectsListShapedData(): void
    {
        [, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->publish('order.created', ['a', 'b'], ['tenantId' => '38112']);
    }

    public function testEmptyDataEncodesAsAJsonObject(): void
    {
        [$publisher, $client] = $this->makeClient();

        $client->publish('order.created', [], ['tenantId' => '38112']);
        $client->flush();

        $encoded = json_encode($publisher->allEnvelopes()[0]);
        $this->assertStringContainsString('"data":{}', $encoded);
    }

    public function testOccurredAtAndSourceCanBeOverridden(): void
    {
        [$publisher, $client] = $this->makeClient();

        $client->publish('order.created', ['orderId' => '1'], [
            'tenantId' => '38112',
            'occurredAt' => '2026-07-24T10:15:23Z',
            'source' => 'cron',
        ]);
        $client->flush();

        $envelope = $publisher->allEnvelopes()[0];
        $this->assertSame('2026-07-24T10:15:23Z', $envelope['occurredAt']);
        $this->assertSame('cron', $envelope['source']);
    }

    /**
     * `date('c')` is the reflex in a legacy PHP codebase, and the envelope
     * schema rejects a numeric offset. Passed through, it would publish, pass
     * SNS, pass the filter policy, and die in the consumer as `schema_invalid`
     * behind a fail-open client - the call site never learns.
     */
    public function testOccurredAtWithAnOffsetIsConvertedToUtcZ(): void
    {
        [$publisher, $client] = $this->makeClient();

        $client->publish('order.created', ['orderId' => '1'], [
            'tenantId' => '38112',
            'occurredAt' => '2026-08-12T15:45:23+05:30',
        ]);
        $client->flush();

        // Same instant, schema-legal format.
        $this->assertSame('2026-08-12T10:15:23Z', $publisher->allEnvelopes()[0]['occurredAt']);
    }

    public function testOccurredAtMillisecondsAreTruncatedNotRejected(): void
    {
        [$publisher, $client] = $this->makeClient();

        $client->publish('order.created', ['orderId' => '1'], [
            'tenantId' => '38112',
            'occurredAt' => '2026-08-12T10:15:23.482Z',
        ]);
        $client->flush();

        $this->assertSame('2026-08-12T10:15:23Z', $publisher->allEnvelopes()[0]['occurredAt']);
    }

    public function testOccurredAtAcceptsAUnixTimestamp(): void
    {
        [$publisher, $client] = $this->makeClient();

        $client->publish('order.created', ['orderId' => '1'], [
            'tenantId' => '38112',
            'occurredAt' => 1786529723,
        ]);
        $client->flush();

        $this->assertSame('2026-08-12T10:15:23Z', $publisher->allEnvelopes()[0]['occurredAt']);
    }

    public function testEveryAcceptedOccurredAtMatchesTheEnvelopeSchema(): void
    {
        [$publisher, $client] = $this->makeClient();

        foreach (['2026-08-12T15:45:23+05:30', '2026-08-12T10:15:23.482Z', 1786529723, 'now'] as $i => $value) {
            $client->publish('order.created', ['orderId' => (string) $i], [
                'tenantId' => '38112',
                'occurredAt' => $value,
            ]);
        }
        $client->flush();

        foreach ($publisher->allEnvelopes() as $envelope) {
            // The exact shape z.string().datetime({offset:false}) accepts.
            // preg_match rather than assertMatchesRegularExpression: that
            // assertion is PHPUnit 9.1+, and this package's declared floor of
            // php >=7.1 caps PHPUnit at 7.5.
            $this->assertSame(
                1,
                preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $envelope['occurredAt']),
                'occurredAt must be a UTC instant with no offset, got ' . $envelope['occurredAt']
            );
        }
    }

    public function testPublishRejectsAnUnparseableOccurredAt(): void
    {
        [, $client] = $this->makeClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a parseable date');
        $client->publish('order.created', ['orderId' => '1'], [
            'tenantId' => '38112',
            'occurredAt' => 'last tuesday-ish',
        ]);
    }

    public function testDomainDerivation(): void
    {
        $this->assertSame('orders', EventsClient::domainForEventType('order.created'));
        $this->assertSame('customers', EventsClient::domainForEventType('customer.merged'));
        // Not every domain is plural - this is why it is a map, not a rule.
        $this->assertSame('loyalty', EventsClient::domainForEventType('loyalty.points_awarded'));
        $this->assertSame('menu', EventsClient::domainForEventType('menu.item_updated'));
    }

    public function testPublishRejectsAnUnregisteredDomain(): void
    {
        [, $client] = $this->makeClient();
        $this->expectException(InvalidArgumentException::class);
        $client->publish('widget.created', ['id' => '1'], ['tenantId' => '38112']);
    }

    public function testExplicitFlushIsUncapped(): void
    {
        [$publisher, $client] = $this->makeClient();

        // 12 chunks worth - more than SHUTDOWN_MAX_CHUNKS. An explicit
        // flush() is the caller choosing to wait, so nothing is dropped.
        for ($i = 0; $i < 120; $i++) {
            $client->publish('order.created', ['n' => $i], ['tenantId' => '38112']);
        }
        $client->flush();

        $this->assertCount(12, $publisher->calls);
        $this->assertCount(120, $publisher->allEnvelopes());
        $this->assertSame(0, $client->failedCount());
    }

    public function testShutdownFlushIsCappedSoADeadBusCannotPinAWorker(): void
    {
        [$publisher, $client] = $this->makeClient();

        for ($i = 0; $i < 120; $i++) {
            $client->publish('order.created', ['n' => $i], ['tenantId' => '38112']);
        }
        $client->flushOnShutdown();

        // 5 batches attempted, the remaining 70 events dropped and counted
        // rather than held on a worker against an unreachable bus.
        $this->assertCount(EventsClient::SHUTDOWN_MAX_CHUNKS, $publisher->calls);
        $this->assertCount(50, $publisher->allEnvelopes());
        $this->assertSame(70, $client->failedCount());
        $this->assertSame(0, $client->queueLength());
    }

    public function testShutdownFlushUnderTheCapBehavesNormally(): void
    {
        [$publisher, $client] = $this->makeClient();

        for ($i = 0; $i < 12; $i++) {
            $client->publish('order.created', ['n' => $i], ['tenantId' => '38112']);
        }
        $client->flushOnShutdown();

        $this->assertCount(2, $publisher->calls);
        $this->assertCount(12, $publisher->allEnvelopes());
        $this->assertSame(0, $client->failedCount());
    }

    public function testFinishRequestBeforeFlushIsSafeWhenTheSapiLacksIt(): void
    {
        [, $client] = $this->makeClient();

        // Under CLI there is no fastcgi_finish_request; the guard must be
        // a no-op rather than a fatal.
        $client->finishRequestBeforeFlush();
        $this->addToAssertionCount(1);
    }

    public function testMonotonicUlidIncrementsWithinTheSameMillisecond(): void
    {
        Ulid::resetMonotonicState();
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $ids[] = Ulid::monotonic();
        }
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }
}
