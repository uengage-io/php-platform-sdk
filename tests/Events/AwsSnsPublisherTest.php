<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Events;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Events\AwsSnsPublisher;
use Uengage\PlatformSdk\Events\EventsException;

/**
 * Stand-in for Aws\Sns\SnsClient. Only `publishBatch` is used, and the
 * AWS SDK's magic-method API means a plain object with that method is
 * indistinguishable from the real client as far as this publisher cares.
 */
class FakeSnsClient
{
    /** @var array<int, array> */
    public $calls = [];

    /** @var array Result returned from publishBatch. */
    public $result = [];

    public function publishBatch(array $args)
    {
        $this->calls[] = $args;
        return $this->result;
    }
}

class AwsSnsPublisherTest extends TestCase
{
    const TOPIC = 'arn:aws:sns:ap-south-1:822426641710:platform-events-dev';

    private function envelope(string $id, array $data = ['orderId' => '1']): array
    {
        return [
            'id' => $id,
            'type' => 'order.status_changed',
            'domain' => 'orders',
            'source' => 'legacy-php',
            'version' => 1,
            'occurredAt' => '2026-07-24T10:15:23Z',
            'tenantId' => '38112',
            'data' => $data,
        ];
    }

    private function makePublisher(): array
    {
        $client = new FakeSnsClient();
        return [$client, new AwsSnsPublisher('ap-south-1', $client)];
    }

    public function testEncodesEachEnvelopeAndSetsFilterAttributes(): void
    {
        [$client, $publisher] = $this->makePublisher();

        $failed = $publisher->publishBatch(self::TOPIC, [$this->envelope('01AAA')]);

        $this->assertSame([], $failed);
        $this->assertCount(1, $client->calls);
        $entry = $client->calls[0]['PublishBatchRequestEntries'][0];
        $this->assertSame('01AAA', $entry['Id']);
        $this->assertSame($this->envelope('01AAA'), json_decode($entry['Message'], true));
        $this->assertSame('order.status_changed', $entry['MessageAttributes']['type']['StringValue']);
        $this->assertSame('orders', $entry['MessageAttributes']['domain']['StringValue']);
    }

    /**
     * The reviewed bug: json_encode returns false on malformed UTF-8, and
     * a false in one entry made the AWS SDK reject the whole PublishBatch.
     * Legacy order payloads carry customer names straight out of the
     * legacy DB, so this is a realistic input, not a hypothetical.
     */
    public function testOneBadUtf8PayloadDoesNotFailItsBatchSiblings(): void
    {
        [$client, $publisher] = $this->makePublisher();

        $bad = $this->envelope('01BAD', ['customerName' => "Jos\xE9"]); // latin-1 e-acute
        $good1 = $this->envelope('01GOOD1');
        $good2 = $this->envelope('01GOOD2');

        $failed = $publisher->publishBatch(self::TOPIC, [$good1, $bad, $good2]);

        $this->assertCount(1, $client->calls);
        $entries = $client->calls[0]['PublishBatchRequestEntries'];

        if (PHP_VERSION_ID >= 70200) {
            // 7.2+ substitutes the bad byte, so nothing is lost.
            $this->assertSame([], $failed);
            $this->assertCount(3, $entries);
        } else {
            // 7.1 cannot substitute; the one bad entry is dropped and the
            // other two still go out.
            $this->assertSame(['01BAD'], $failed);
            $this->assertCount(2, $entries);
        }
        $ids = array_column($entries, 'Id');
        $this->assertContains('01GOOD1', $ids);
        $this->assertContains('01GOOD2', $ids);
    }

    public function testEveryMessageIsValidJsonEvenWithBadInput(): void
    {
        [$client, $publisher] = $this->makePublisher();

        $failed = $publisher->publishBatch(self::TOPIC, [
            $this->envelope('01BAD', ['customerName' => "Jos\xE9"]),
        ]);

        // Same 7.1/7.2 split as testOneBadUtf8PayloadDoesNotFailItsBatchSiblings,
        // but here the bad envelope is the ONLY one. On 7.1 it is dropped, so
        // the batch is empty and no PublishBatch call is made at all — this
        // test used to index $client->calls[0] unconditionally and died with
        // "Undefined offset: 0" on the version the package claims to support.
        // The invariant still holds either way: nothing invalid reaches SNS.
        if (PHP_VERSION_ID < 70200) {
            $this->assertSame(['01BAD'], $failed);
            $this->assertCount(0, $client->calls);
            return;
        }

        $this->assertSame([], $failed);
        $this->assertCount(1, $client->calls);
        foreach ($client->calls[0]['PublishBatchRequestEntries'] as $entry) {
            $this->assertIsString($entry['Message']);
            $this->assertNotFalse($entry['Message']);
            $this->assertNotNull(json_decode($entry['Message'], true));
        }
    }

    public function testOversizedEnvelopeIsDroppedNotSentToSns(): void
    {
        [$client, $publisher] = $this->makePublisher();

        $huge = $this->envelope('01HUGE', ['blob' => str_repeat('x', 300000)]);
        $failed = $publisher->publishBatch(self::TOPIC, [$this->envelope('01OK'), $huge]);

        $this->assertSame(['01HUGE'], $failed);
        $entries = $client->calls[0]['PublishBatchRequestEntries'];
        $this->assertCount(1, $entries);
        $this->assertSame('01OK', $entries[0]['Id']);
    }

    public function testSkipsTheApiCallEntirelyWhenEveryEntryWasDropped(): void
    {
        [$client, $publisher] = $this->makePublisher();

        $failed = $publisher->publishBatch(self::TOPIC, [
            $this->envelope('01HUGE', ['blob' => str_repeat('x', 300000)]),
        ]);

        $this->assertSame(['01HUGE'], $failed);
        $this->assertCount(0, $client->calls, 'no point calling SNS with an empty batch');
    }

    public function testReportsPerEntryFailuresFromSns(): void
    {
        [$client, $publisher] = $this->makePublisher();
        $client->result = ['Failed' => [['Id' => '01BBB']]];

        $failed = $publisher->publishBatch(self::TOPIC, [
            $this->envelope('01AAA'),
            $this->envelope('01BBB'),
        ]);

        $this->assertSame(['01BBB'], $failed);
    }

    public function testWrapsTransportFailuresAsEventsException(): void
    {
        $client = new class extends FakeSnsClient {
            public function publishBatch(array $args)
            {
                throw new \RuntimeException('network down');
            }
        };
        $publisher = new AwsSnsPublisher('ap-south-1', $client);

        $this->expectException(EventsException::class);
        $publisher->publishBatch(self::TOPIC, [$this->envelope('01AAA')]);
    }

    public function testEmptyBatchIsANoOp(): void
    {
        [$client, $publisher] = $this->makePublisher();
        $this->assertSame([], $publisher->publishBatch(self::TOPIC, []));
        $this->assertCount(0, $client->calls);
    }
}
