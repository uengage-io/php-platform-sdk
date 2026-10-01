<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Alerts;

use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Alerts\AlertsClient;
use Uengage\PlatformSdk\Alerts\AlertsException;
use Uengage\PlatformSdk\Alerts\AwsSqsSender;

/**
 * Stand-in for Aws\Sqs\SqsClient. Only `sendMessageBatch` is used, and the
 * AWS SDK's magic-method API means a plain object with that method is
 * indistinguishable from the real client as far as the sender cares.
 */
class FakeSqsClient
{
    /** @var array<int, array> */
    public $calls = [];

    /** @var array<int, array> Results returned per call, in order; [] once exhausted. */
    public $results = [];

    /** @var int|null Throw on this call index (0-based). */
    public $throwOnCall = null;

    public function sendMessageBatch(array $args)
    {
        $index = count($this->calls);
        $this->calls[] = $args;
        if ($this->throwOnCall === $index) {
            throw new \RuntimeException('network down');
        }
        return isset($this->results[$index]) ? $this->results[$index] : [];
    }
}

class AwsSqsSenderTest extends TestCase
{
    const QUEUE = 'https://sqs.ap-south-1.amazonaws.com/571896969292/alerts-uat.fifo';

    /** @var string|false */
    private $errorLog;

    protected function setUp(): void
    {
        $this->errorLog = ini_get('error_log');
        ini_set('error_log', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog === false ? '' : $this->errorLog);
    }

    private function message(string $id, array $context = ['balance' => 1]): array
    {
        return AlertsClient::buildMessage([
            'title' => 'Wallet balance low',
            'description' => 'Balance fell below the configured threshold.',
            'tenant' => ['parentId' => 7175, 'businessId' => 38112],
            'type' => 'wallet.low_balance',
            'context' => $context,
        ], '2026-09-28T12:00:00Z', $id);
    }

    private function make(): array
    {
        $client = new FakeSqsClient();
        return [$client, new AwsSqsSender(null, $client, 0)];
    }

    public function testSendsWithGroupAndDedupIdsPerTheContract(): void
    {
        [$client, $sender] = $this->make();

        $failed = $sender->sendBatch(self::QUEUE, [$this->message('01AAA'), $this->message('01BBB')]);

        $this->assertSame([], $failed);
        $this->assertCount(1, $client->calls);
        $this->assertSame(self::QUEUE, $client->calls[0]['QueueUrl']);
        $entries = $client->calls[0]['Entries'];
        $this->assertSame(['01AAA', '01BBB'], array_column($entries, 'Id'), 'order is preserved');
        foreach ($entries as $entry) {
            $this->assertSame('7175#38112#wallet.low_balance', $entry['MessageGroupId']);
            $this->assertSame($entry['Id'], $entry['MessageDeduplicationId']);
        }
        $this->assertSame($this->message('01AAA'), json_decode($entries[0]['MessageBody'], true));
    }

    public function testOneBadUtf8MessageDoesNotFailItsBatchSiblings(): void
    {
        [$client, $sender] = $this->make();

        $failed = $sender->sendBatch(self::QUEUE, [
            $this->message('01GOOD1'),
            $this->message('01BAD', ['name' => "Jos\xE9"]),
            $this->message('01GOOD2'),
        ]);

        $ids = array_column($client->calls[0]['Entries'], 'Id');
        if (PHP_VERSION_ID >= 70200) {
            $this->assertSame([], $failed);
            $this->assertSame(['01GOOD1', '01BAD', '01GOOD2'], $ids);
        } else {
            $this->assertSame(['01BAD'], $failed);
            $this->assertSame(['01GOOD1', '01GOOD2'], $ids);
        }
    }

    public function testSplitsABatchThatWouldExceedTheTotalPayloadLimit(): void
    {
        [$client, $sender] = $this->make();
        // ~50 KB each (context caps at 50 x 1 KiB), so 10 of them exceed 256 KiB.
        $context = [];
        for ($i = 0; $i < 50; $i++) {
            $context['k' . $i] = str_repeat('v', 1000);
        }
        $messages = [];
        for ($i = 0; $i < 10; $i++) {
            $messages[] = $this->message('01M' . $i, $context);
        }

        $failed = $sender->sendBatch(self::QUEUE, $messages);

        $this->assertSame([], $failed);
        $this->assertGreaterThan(1, count($client->calls));
        $sent = [];
        foreach ($client->calls as $call) {
            $bytes = 0;
            foreach ($call['Entries'] as $entry) {
                $bytes += strlen($entry['MessageBody']);
                $sent[] = $entry['Id'];
            }
            $this->assertLessThanOrEqual(AwsSqsSender::MAX_BATCH_BYTES, $bytes);
        }
        $this->assertSame(array_column($messages, 'id'), $sent, 'order is preserved across sub-batches');
    }

    public function testReportsPerEntryFailures(): void
    {
        [$client, $sender] = $this->make();
        $client->results = [['Failed' => [['Id' => '01BBB', 'Code' => 'AccessDenied']]]];

        $failed = $sender->sendBatch(self::QUEUE, [$this->message('01AAA'), $this->message('01BBB')]);

        $this->assertSame(['01BBB'], $failed);
    }

    public function testRetriesServerSideFailuresOnceWithOnlyThoseEntries(): void
    {
        [$client, $sender] = $this->make();
        $client->results = [
            ['Failed' => [
                ['Id' => '01AAA', 'Code' => 'ThrottlingException', 'SenderFault' => false],
                ['Id' => '01BBB', 'Code' => 'AccessDenied', 'SenderFault' => true],
            ]],
            [],
        ];

        $failed = $sender->sendBatch(self::QUEUE, [
            $this->message('01AAA'),
            $this->message('01BBB'),
            $this->message('01CCC'),
        ]);

        $this->assertSame(['01BBB'], $failed, 'the throttled entry succeeded on retry; the sender fault is not retried');
        $this->assertCount(2, $client->calls);
        $retried = $client->calls[1]['Entries'];
        $this->assertSame(['01AAA'], array_column($retried, 'Id'));
        $this->assertSame($client->calls[0]['Entries'][0], $retried[0], 'same entry, same dedup id');
    }

    public function testReportsEntriesThatFailTheRetryToo(): void
    {
        [$client, $sender] = $this->make();
        $throttled = ['Failed' => [['Id' => '01AAA', 'Code' => 'ThrottlingException', 'SenderFault' => false]]];
        $client->results = [$throttled, $throttled];

        $this->assertSame(['01AAA'], $sender->sendBatch(self::QUEUE, [$this->message('01AAA')]));
        $this->assertCount(2, $client->calls, 'one retry only');
    }

    public function testATransportErrorOnTheRetryFailsOnlyTheRetriedEntries(): void
    {
        [$client, $sender] = $this->make();
        $client->results = [['Failed' => [['Id' => '01AAA', 'Code' => 'InternalError', 'SenderFault' => false]]]];
        $client->throwOnCall = 1;

        $failed = $sender->sendBatch(self::QUEUE, [$this->message('01AAA'), $this->message('01BBB')]);

        $this->assertSame(['01AAA'], $failed);
    }

    public function testWrapsTransportFailuresAsAlertsException(): void
    {
        [$client, $sender] = $this->make();
        $client->throwOnCall = 0;

        $this->expectException(AlertsException::class);
        $sender->sendBatch(self::QUEUE, [$this->message('01AAA')]);
    }

    public function testRegionFromQueueUrl(): void
    {
        $this->assertSame('ap-south-1', AwsSqsSender::regionFromQueueUrl(self::QUEUE));
        $this->assertSame('eu-west-1', AwsSqsSender::regionFromQueueUrl('https://eu-west-1.queue.amazonaws.com/1/q.fifo'));
        $this->assertNull(AwsSqsSender::regionFromQueueUrl('http://localhost:4566/000000000000/q.fifo'));
    }

    public function testLocalEndpointOnlyForNonAwsQueueUrls(): void
    {
        $this->assertNull(AwsSqsSender::localEndpoint(self::QUEUE));
        $this->assertNull(AwsSqsSender::localEndpoint('https://eu-west-1.queue.amazonaws.com/1/q.fifo'));
        $this->assertSame(
            'http://localhost:4566',
            AwsSqsSender::localEndpoint('http://localhost:4566/000000000000/q.fifo')
        );
    }

    public function testMissingAwsSdkThrowsForTheClientToFailOpenOn(): void
    {
        if (AwsSqsSender::isAvailable()) {
            $this->markTestSkipped('aws/aws-sdk-php is installed');
        }
        $this->expectException(AlertsException::class);
        $this->expectExceptionMessage('aws/aws-sdk-php is not installed');
        (new AwsSqsSender())->sendBatch(self::QUEUE, [$this->message('01AAA')]);
    }

    public function testEmptyBatchIsANoOp(): void
    {
        [$client, $sender] = $this->make();
        $this->assertSame([], $sender->sendBatch(self::QUEUE, []));
        $this->assertCount(0, $client->calls);
    }
}
