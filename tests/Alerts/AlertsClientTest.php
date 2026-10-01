<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Alerts;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Uengage\PlatformSdk\Alerts\AlertsApiException;
use Uengage\PlatformSdk\Alerts\AlertsClient;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Tests\Support\StubSqsSender;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;

class AlertsClientTest extends TestCase
{
    const QUEUE_URL = 'https://sqs.ap-south-1.amazonaws.com/571896969292/alerts-uat.fifo';

    /** @var StubHttp */
    private $stub;

    /** @var StubSqsSender */
    private $sender;

    /** @var AlertsClient */
    private $alerts;

    /** @var string|false */
    private $errorLog;

    protected function setUp(): void
    {
        $this->stub = new StubHttp();
        $this->sender = new StubSqsSender();
        $this->alerts = $this->makeClient(self::QUEUE_URL, null);
        // flush() reports failures through error_log(); keep them out of the
        // test output.
        $this->errorLog = ini_get('error_log');
        ini_set('error_log', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog === false ? '' : $this->errorLog);
    }

    private function makeClient(?string $queueUrl, ?string $env): AlertsClient
    {
        $config = new Config(
            'https://api.test',
            'https://api.test/auth/business',
            'https://api.test/auth/customer',
            new StaticBearerTokenSource('test.jwt'),
            'test-service',
            null,
            null,
            null,
            null,
            $queueUrl,
            $env
        );
        return new AlertsClient($config, new RequestSigner($config, $this->stub->client), $this->sender);
    }

    private function sampleAlert(): array
    {
        return [
            'title' => '  Wallet balance low  ',
            'description' => 'Balance fell below the configured threshold.',
            'tenant' => ['parentId' => 7175, 'businessId' => '38112'],
            'type' => 'wallet.low_balance',
            'context' => ['balance' => 120.5, 'threshold' => 500, 'currency' => 'INR', 'autoTopUp' => false],
        ];
    }

    private function sampleAlertRow(): array
    {
        return [
            'id' => '38112.wallet.low_balance.01K6A0000000000000000000AB',
            'parentId' => '7175',
            'businessId' => '38112',
            'title' => 'Wallet balance low',
            'description' => 'Balance fell below the configured threshold.',
            'type' => 'wallet.low_balance',
            'category' => 'business',
            'context' => ['balance' => 120.5],
            'notify' => false,
            'status' => 'open',
            'count' => 3,
            'firstSeenAt' => '2026-09-01T10:00:00Z',
            'lastSeenAt' => '2026-09-02T10:00:00Z',
            'updatedAt' => '2026-09-02T10:00:00Z',
            'userIds' => ['102', '103'],
        ];
    }

    private function expectInvalid(array $alert, string $messageFragment): void
    {
        try {
            $this->alerts->raise($alert);
            $this->fail('expected InvalidArgumentException containing "' . $messageFragment . '"');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($messageFragment, $e->getMessage());
        }
        $this->assertSame(0, $this->alerts->queueLength(), 'an invalid alert must not be queued');
    }

    // ─── raise: message ──────────────────────────────────────────────────

    public function testRaiseSendsTheMessageToTheQueue(): void
    {
        $id = $this->alerts->raise($this->sampleAlert());
        $this->alerts->flush();

        $this->assertCount(1, $this->sender->calls);
        $this->assertSame(self::QUEUE_URL, $this->sender->calls[0]['queueUrl']);
        $messages = $this->sender->allMessages();
        $this->assertCount(1, $messages);
        $message = $messages[0];

        $this->assertSame(
            ['title', 'description', 'tenant', 'notify', 'type', 'context', 'id', 'category', 'occurredAt'],
            array_keys($message)
        );
        $this->assertSame($id, $message['id']);
        $this->assertSame(1, preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $message['id']), 'id is a ULID');
        $this->assertSame('Wallet balance low', $message['title'], 'title is trimmed');
        $this->assertSame(['parentId' => '7175', 'businessId' => '38112'], $message['tenant']);
        $this->assertFalse($message['notify'], 'notify defaults to false');
        $this->assertSame('business', $message['category']);
        $this->assertSame(
            ['balance' => 120.5, 'threshold' => 500, 'currency' => 'INR', 'autoTopUp' => false],
            $message['context']
        );
        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $message['occurredAt']));
        $this->assertSame('7175#38112#wallet.low_balance', AlertsClient::messageGroupId($message));
    }

    public function testEachRaiseMintsAFreshIncreasingId(): void
    {
        $a = $this->alerts->raise($this->sampleAlert());
        $b = $this->alerts->raise($this->sampleAlert());
        $this->assertNotSame($a, $b, 'the id is the FIFO dedup id; two raises must not collapse');
        $this->assertLessThan(0, strcmp($a, $b));
    }

    public function testRaiseIsBufferedUntilFlush(): void
    {
        $this->alerts->raise($this->sampleAlert());
        $this->assertSame(1, $this->alerts->queueLength());
        $this->assertCount(0, $this->sender->calls);

        $this->alerts->flush();
        $this->assertSame(0, $this->alerts->queueLength());
        $this->assertCount(1, $this->sender->calls);

        $this->alerts->flush();
        $this->assertCount(1, $this->sender->calls, 'flushing an empty queue sends nothing');
    }

    public function testFlushSendsInBatchesOfTenInRaiseOrder(): void
    {
        $ids = [];
        for ($i = 0; $i < 23; $i++) {
            $ids[] = $this->alerts->raise($this->sampleAlert());
        }
        $this->alerts->flush();

        $this->assertSame([10, 10, 3], array_map(function ($call) {
            return count($call['messages']);
        }, $this->sender->calls));
        $this->assertSame($ids, array_column($this->sender->allMessages(), 'id'));
        $this->assertSame(0, $this->alerts->failedCount());
    }

    public function testShutdownFlushIsCapped(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->alerts->raise($this->sampleAlert());
        }
        $this->alerts->flushOnShutdown();

        $this->assertCount(AlertsClient::SHUTDOWN_MAX_CHUNKS, $this->sender->calls);
        $this->assertSame(10, $this->alerts->failedCount(), 'the 10 past the cap are counted as failed');
        $this->assertSame(0, $this->alerts->queueLength());
    }

    public function testRaiseFailsOpenWhenSqsIsDown(): void
    {
        $this->sender->throwable = new RuntimeException('sqs unreachable');
        $this->alerts->raise($this->sampleAlert());
        $this->alerts->raise($this->sampleAlert());

        $this->alerts->flush();

        $this->assertSame(2, $this->alerts->failedCount());
        $this->assertSame(0, $this->alerts->queueLength(), 'failed alerts are not retried');
    }

    public function testPartialFailuresAreCounted(): void
    {
        $this->alerts->raise($this->sampleAlert());
        $bad = $this->alerts->raise($this->sampleAlert());
        $this->alerts->raise($this->sampleAlert());
        $this->sender->failIds = [$bad];

        $this->alerts->flush();

        $this->assertSame(1, $this->alerts->failedCount());
        $this->assertCount(3, $this->sender->allMessages());
    }

    public function testFailedCountIsCumulative(): void
    {
        $this->sender->throwable = new RuntimeException('down');
        $this->alerts->raise($this->sampleAlert());
        $this->alerts->flush();
        $this->sender->throwable = null;
        $this->alerts->raise($this->sampleAlert());
        $this->alerts->flush();

        $this->assertSame(1, $this->alerts->failedCount(), 'callers diff it before and after a flush');
    }

    public function testFullQueueDropsTheOldest(): void
    {
        $first = $this->alerts->raise($this->sampleAlert());
        for ($i = 0; $i < AlertsClient::MAX_QUEUE_SIZE; $i++) {
            $this->alerts->raise($this->sampleAlert());
        }
        $this->assertSame(AlertsClient::MAX_QUEUE_SIZE, $this->alerts->queueLength());
        $this->assertSame(1, $this->alerts->droppedCount());

        $this->alerts->flush();
        $this->assertNotContains($first, array_column($this->sender->allMessages(), 'id'));
    }

    // ─── raise: queue URL resolution ─────────────────────────────────────

    public function testExplicitQueueUrlWinsOverEnv(): void
    {
        $alerts = $this->makeClient('https://sqs.eu-west-1.amazonaws.com/1/custom.fifo', 'prod');
        $this->assertSame('https://sqs.eu-west-1.amazonaws.com/1/custom.fifo', $alerts->queueUrl());
    }

    public function testEnvPicksTheDefaultQueue(): void
    {
        $this->assertSame(AlertsClient::ALERT_QUEUE_URLS['uat'], $this->makeClient(null, 'uat')->queueUrl());
        $this->assertSame(AlertsClient::ALERT_QUEUE_URLS['prod'], $this->makeClient(null, 'prod')->queueUrl());
        $this->assertSame(
            'https://sqs.ap-south-1.amazonaws.com/822426641710/alerts-prod.fifo',
            AlertsClient::ALERT_QUEUE_URLS['prod']
        );
    }

    public function testNoQueueFailsOpenWithoutCallingSqs(): void
    {
        foreach ([null, 'dev'] as $env) {
            $sender = new StubSqsSender();
            $this->sender = $sender;
            $alerts = $this->makeClient(null, $env);
            $this->assertNull($alerts->queueUrl());

            $alerts->raise($this->sampleAlert());
            $alerts->flush();

            $this->assertSame(1, $alerts->failedCount(), 'env ' . var_export($env, true));
            $this->assertCount(0, $sender->calls);
        }
    }

    public function testEmptyContextEncodesAsAJsonObject(): void
    {
        $alert = $this->sampleAlert();
        unset($alert['context']);
        $this->alerts->raise($alert);
        $this->alerts->flush();

        $message = $this->sender->allMessages()[0];
        $this->assertSame('{}', json_encode($message['context']));
    }

    public function testCallerSuppliedCategoryIdAndOccurredAtAreIgnored(): void
    {
        $alert = $this->sampleAlert();
        $alert['category'] = 'engineering';
        $alert['occurredAt'] = '1999-01-01T00:00:00Z';
        $alert['id'] = 'caller-chosen';
        $alert['extra'] = 'dropped';
        $message = AlertsClient::buildMessage($alert, '2026-09-28T12:00:00Z', '01K6A0000000000000000000AB');

        $this->assertSame('business', $message['category']);
        $this->assertSame('2026-09-28T12:00:00Z', $message['occurredAt']);
        $this->assertSame('01K6A0000000000000000000AB', $message['id']);
        $this->assertArrayNotHasKey('extra', $message);
    }

    public function testNotifyIsPassedThrough(): void
    {
        $alert = $this->sampleAlert();
        $alert['notify'] = true;
        $this->assertTrue(AlertsClient::buildMessage($alert, '2026-09-28T12:00:00Z')['notify']);
    }

    public function testEveryRegisteredTypeHasAKnownCategory(): void
    {
        foreach (AlertsClient::ALERT_TYPES as $type => $category) {
            $this->assertContains($category, AlertsClient::ALERT_CATEGORIES, $type);
            $this->assertSame($category, AlertsClient::categoryForType($type));
        }
    }

    // ─── raise: validation ───────────────────────────────────────────────

    public function testRejectsUnknownType(): void
    {
        $alert = $this->sampleAlert();
        $alert['type'] = 'wallet.exploded';
        $this->expectInvalid($alert, 'type must be one of');
    }

    public function testRejectsBlankTitle(): void
    {
        $alert = $this->sampleAlert();
        $alert['title'] = '   ';
        $this->expectInvalid($alert, 'raise.title');
    }

    public function testRejectsOverLongTitleAndDescription(): void
    {
        $alert = $this->sampleAlert();
        $alert['title'] = str_repeat('a', 201);
        $this->expectInvalid($alert, 'raise.title');

        $alert = $this->sampleAlert();
        $alert['title'] = str_repeat('a', 200);
        $alert['description'] = str_repeat('d', 4001);
        $this->expectInvalid($alert, 'raise.description');
    }

    public function testAcceptsBoundaryLengths(): void
    {
        $alert = $this->sampleAlert();
        $alert['title'] = str_repeat('a', 200);
        $alert['description'] = str_repeat('d', 4000);
        $alert['context'] = ['k' => str_repeat('v', 1024), str_repeat('x', 64) => 1];
        $message = AlertsClient::buildMessage($alert, '2026-09-28T12:00:00Z');
        $this->assertSame(200, strlen($message['title']));
    }

    public function testRejectsMissingOrInvalidTenant(): void
    {
        $alert = $this->sampleAlert();
        unset($alert['tenant']);
        $this->expectInvalid($alert, 'tenant must be an array');

        $alert = $this->sampleAlert();
        unset($alert['tenant']['businessId']);
        $this->expectInvalid($alert, 'raise.tenant.businessId');

        $alert = $this->sampleAlert();
        $alert['tenant']['parentId'] = 0;
        $this->expectInvalid($alert, 'raise.tenant.parentId');

        $alert = $this->sampleAlert();
        $alert['tenant']['parentId'] = '  ';
        $this->expectInvalid($alert, 'raise.tenant.parentId');

        $alert = $this->sampleAlert();
        $alert['tenant']['businessId'] = str_repeat('9', 49);
        $this->expectInvalid($alert, 'raise.tenant.businessId');

        // A dot would split the public alert id; the FIFO group id must stay <= 128 chars.
        foreach (['7.5', 'ab cd', "caf\u{e9}"] as $bad) {
            $alert = $this->sampleAlert();
            $alert['tenant']['businessId'] = $bad;
            $this->expectInvalid($alert, 'raise.tenant.businessId');
        }
    }

    public function testWholeFloatTenantIdsWithinTheSafeIntegerRange(): void
    {
        $alert = $this->sampleAlert();
        $alert['tenant']['businessId'] = 7175.0;
        $this->assertSame('7175', AlertsClient::buildMessage($alert, '2026-09-28T12:00:00Z')['tenant']['businessId']);

        // 1e60 would stringify to a 61-digit id the consumer rejects.
        $alert = $this->sampleAlert();
        $alert['tenant']['businessId'] = 1e60;
        $this->expectInvalid($alert, 'raise.tenant.businessId');
    }

    /**
     * Lengths are UTF-16 code units, as the consumer's zod schema counts them:
     * an emoji above U+FFFF counts 2, so 150 of them is 300 > 200.
     */
    public function testTitleLengthCountsUtf16Units(): void
    {
        $emoji = "\u{1F525}";
        $alert = $this->sampleAlert();
        $alert['title'] = str_repeat($emoji, 150);
        $this->expectInvalid($alert, 'raise.title');

        $alert = $this->sampleAlert();
        $alert['title'] = str_repeat($emoji, 100);
        $this->assertSame(
            str_repeat($emoji, 100),
            AlertsClient::buildMessage($alert, '2026-09-28T12:00:00Z')['title']
        );
    }

    public function testTrimsUnicodeWhitespaceLikeTheConsumer(): void
    {
        $alert = $this->sampleAlert();
        $alert['title'] = "\u{00A0}\u{3000}Wallet low\u{FEFF}\u{2028}";
        $alert['tenant']['businessId'] = "\u{00A0}7175\u{3000}";
        $message = AlertsClient::buildMessage($alert, '2026-09-28T12:00:00Z');
        $this->assertSame('Wallet low', $message['title']);
        $this->assertSame('7175', $message['tenant']['businessId']);

        $alert = $this->sampleAlert();
        $alert['description'] = "\u{00A0}\u{3000}";
        $this->expectInvalid($alert, 'raise.description');
    }

    public function testIntAndStringTenantIdsNormaliseIdentically(): void
    {
        $a = $this->sampleAlert();
        $a['tenant'] = ['parentId' => 7175, 'businessId' => 38112];
        $b = $this->sampleAlert();
        $b['tenant'] = ['parentId' => ' 7175 ', 'businessId' => '38112'];

        $this->assertSame(
            AlertsClient::buildMessage($a, '2026-09-28T12:00:00Z')['tenant'],
            AlertsClient::buildMessage($b, '2026-09-28T12:00:00Z')['tenant']
        );
    }

    public function testRejectsNonBooleanNotify(): void
    {
        $alert = $this->sampleAlert();
        $alert['notify'] = 1;
        $this->expectInvalid($alert, 'notify must be a boolean');
    }

    public function testRejectsNestedContext(): void
    {
        $alert = $this->sampleAlert();
        $alert['context'] = ['order' => ['id' => 1]];
        $this->expectInvalid($alert, 'context.order must be a string, number or boolean');

        $alert = $this->sampleAlert();
        $alert['context'] = ['missing' => null];
        $this->expectInvalid($alert, 'context.missing');
    }

    public function testRejectsListShapedContext(): void
    {
        $alert = $this->sampleAlert();
        $alert['context'] = ['a', 'b'];
        $this->expectInvalid($alert, 'not a list');
    }

    public function testRejectsTooManyContextKeys(): void
    {
        $context = [];
        for ($i = 0; $i < 51; $i++) {
            $context['k' . $i] = $i;
        }
        $alert = $this->sampleAlert();
        $alert['context'] = $context;
        $this->expectInvalid($alert, 'at most 50 keys');
    }

    public function testRejectsBadContextKeysAndValues(): void
    {
        $alert = $this->sampleAlert();
        $alert['context'] = ['' => 'x'];
        $this->expectInvalid($alert, 'context keys must be 1-64');

        $alert = $this->sampleAlert();
        $alert['context'] = [str_repeat('k', 65) => 'x'];
        $this->expectInvalid($alert, 'context keys must be 1-64');

        $alert = $this->sampleAlert();
        $alert['context'] = ['note' => str_repeat('v', 1025)];
        $this->expectInvalid($alert, 'longer than 1024');

        $alert = $this->sampleAlert();
        $alert['context'] = ['ratio' => INF];
        $this->expectInvalid($alert, 'finite number');
    }

    // ─── list ────────────────────────────────────────────────────────────

    public function testListBuildsTheQueryAndUnwrapsThePage(): void
    {
        $this->stub->pushJson(200, ['items' => [$this->sampleAlertRow()], 'nextCursor' => 'cur-2']);

        $page = $this->alerts->list([
            'businessId' => 38112,
            'status' => 'open',
            'limit' => '10',
            'cursor' => 'cur-1',
        ]);

        $call = $this->stub->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame(
            'https://api.test/v1/alerts?businessId=38112&status=open&limit=10&cursor=cur-1',
            $call['url']
        );
        $this->assertSame('Bearer test.jwt', $call['headers']['authorization']);
        $this->assertSame('cur-2', $page['nextCursor']);
        $this->assertCount(1, $page['items']);
        $this->assertSame('38112.wallet.low_balance.01K6A0000000000000000000AB', $page['items'][0]['id']);
        $this->assertSame(3, $page['items'][0]['count']);
        $this->assertSame(['102', '103'], $page['items'][0]['userIds']);
    }

    public function testListReturnsNullCursorOnTheLastPage(): void
    {
        $this->stub->pushJson(200, ['items' => [], 'nextCursor' => null]);
        $page = $this->alerts->list(['type' => 'pg.invalid_credentials', 'category' => 'business']);

        $this->assertSame(['items' => [], 'nextCursor' => null], $page);
        $this->assertSame(
            'https://api.test/v1/alerts?type=pg.invalid_credentials&category=business',
            $this->stub->lastCall()['url']
        );
    }

    public function testListRequiresAFilter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one of businessId, parentId, type, category, status, userId or notify');
        $this->alerts->list(['limit' => 10]);
    }

    public function testListAcceptsNotifyTrueAsAFilter(): void
    {
        $this->stub->pushJson(200, ['items' => [], 'nextCursor' => null]);
        $this->alerts->list(['notify' => true, 'status' => 'open', 'limit' => 100]);
        $this->assertSame(
            'https://api.test/v1/alerts?status=open&notify=true&limit=100',
            $this->stub->lastCall()['url']
        );

        $this->stub->pushJson(200, ['items' => [], 'nextCursor' => null]);
        $this->alerts->list(['notify' => true]);
        $this->assertSame('https://api.test/v1/alerts?notify=true', $this->stub->lastCall()['url']);
    }

    public function testListNotifyFalseIsNotAFilter(): void
    {
        $this->stub->pushJson(200, ['items' => [], 'nextCursor' => null]);
        $this->alerts->list(['businessId' => '6', 'notify' => false]);
        $this->assertSame('https://api.test/v1/alerts?businessId=6', $this->stub->lastCall()['url']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one of businessId, parentId, type, category, status, userId or notify');
        $this->alerts->list(['notify' => false]);
    }

    public function testListAcceptsStatusAlone(): void
    {
        $this->stub->pushJson(200, ['items' => [], 'nextCursor' => null]);
        $this->alerts->list(['status' => 'open']);
        $this->assertSame('https://api.test/v1/alerts?status=open', $this->stub->lastCall()['url']);
    }

    public function testListAcceptsUserIdAlone(): void
    {
        $this->stub->pushJson(200, ['items' => [], 'nextCursor' => null]);
        $this->alerts->list(['userId' => '102']);
        $this->assertSame('https://api.test/v1/alerts?userId=102', $this->stub->lastCall()['url']);
    }

    public function testListCombinesUserIdWithOtherFiltersAndStringifiesInts(): void
    {
        $this->stub->pushJson(200, ['items' => [], 'nextCursor' => 'cur-2']);
        $page = $this->alerts->list([
            'userId' => 102,
            'businessId' => 6,
            'status' => 'open',
            'notify' => true,
            'limit' => 100,
        ]);
        $this->assertSame(
            'https://api.test/v1/alerts?businessId=6&status=open&notify=true&userId=102&limit=100',
            $this->stub->lastCall()['url']
        );
        // A userId page may be short: empty items with a cursor is valid.
        $this->assertSame(['items' => [], 'nextCursor' => 'cur-2'], $page);

        $this->stub->pushJson(200, ['items' => [], 'nextCursor' => null]);
        $this->alerts->list(['userId' => '  ops user@x ']);
        $this->assertSame('https://api.test/v1/alerts?userId=ops+user%40x', $this->stub->lastCall()['url']);

        $this->stub->pushJson(200, ['items' => [], 'nextCursor' => null]);
        $this->alerts->list(['userId' => str_repeat('u', 64)]);
        $this->assertSame('https://api.test/v1/alerts?userId=' . str_repeat('u', 64), $this->stub->lastCall()['url']);
    }

    public function testListRejectsBadValues(): void
    {
        foreach (
            [
                [['businessId' => '1', 'status' => 'closed'], 'list.status'],
                [['category' => 'ops'], 'list.category'],
                [['type' => 'nope.nope'], 'type must be one of'],
                [['businessId' => '1', 'limit' => 0], 'limit must be an integer'],
                [['businessId' => '1', 'limit' => 101], 'limit must be an integer'],
                [['notify' => 'true'], 'notify must be a boolean'],
                [['userId' => ''], 'list.userId'],
                [['userId' => '   '], 'list.userId'],
                [['userId' => str_repeat('u', 65)], 'list.userId: must be an integer or a 1-64 character string'],
                [['userId' => 1.5], 'list.userId'],
                [['userId' => true], 'list.userId'],
                [['userId' => ['102']], 'list.userId'],
            ] as $case
        ) {
            try {
                $this->alerts->list($case[0]);
                $this->fail('expected InvalidArgumentException for ' . json_encode($case[0]));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($case[1], $e->getMessage());
            }
        }
        $this->assertCount(0, $this->stub->getCalls());
    }

    public function testListThrowsApiExceptionOnError(): void
    {
        $this->stub->pushResponse(403, '{"error":"forbidden"}');
        try {
            $this->alerts->list(['businessId' => '1']);
            $this->fail('expected AlertsApiException');
        } catch (AlertsApiException $e) {
            $this->assertSame(403, $e->getStatus());
            $this->assertSame('{"error":"forbidden"}', $e->getBody());
        }
    }

    public function testListRejectsAResponseWithoutItems(): void
    {
        $this->stub->pushJson(200, ['alerts' => []]);
        $this->expectException(AlertsApiException::class);
        $this->expectExceptionMessage('missing the `items` array');
        $this->alerts->list(['businessId' => '1']);
    }

    // ─── get ─────────────────────────────────────────────────────────────

    public function testGetEncodesTheId(): void
    {
        $this->stub->pushJson(200, $this->sampleAlertRow());

        $alert = $this->alerts->get('38112.wallet.low_balance.01K6A0000000000000000000AB');

        $call = $this->stub->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame(
            'https://api.test/v1/alerts/38112.wallet.low_balance.01K6A0000000000000000000AB',
            $call['url'],
            'ids are URL-safe (businessId.type.ULID) and pass through rawurlencode unchanged'
        );
        $this->assertSame('open', $alert['status']);
        $this->assertSame('2026-09-01T10:00:00Z', $alert['firstSeenAt']);
    }

    public function testGetThrowsOnNotFound(): void
    {
        $this->stub->pushResponse(404, '{"error":"not_found"}');
        $this->expectException(AlertsApiException::class);
        $this->alerts->get('38112.missing');
    }

    public function testGetRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->alerts->get('');
    }

    // ─── setStatus ───────────────────────────────────────────────────────

    public function testSetStatusPostsTheBody(): void
    {
        $row = $this->sampleAlertRow();
        $row['status'] = 'resolved';
        $this->stub->pushJson(200, $row);

        $alert = $this->alerts->setStatus('38112.abc123', [
            'status' => 'resolved',
            'comment' => 'Wallet topped up',
            'actor' => 'ops@uengage.in',
        ]);

        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.test/v1/alerts/38112.abc123/status', $call['url']);
        $this->assertSame(
            ['status' => 'resolved', 'actor' => 'ops@uengage.in', 'comment' => 'Wallet topped up'],
            json_decode((string) $call['body'], true)
        );
        $this->assertSame('application/json', $call['headers']['content-type']);
        $this->assertArrayHasKey('idempotency-key', $call['headers']);
        $this->assertSame('resolved', $alert['status']);
    }

    public function testReopenNeedsNoComment(): void
    {
        $this->stub->pushJson(200, $this->sampleAlertRow());
        $this->alerts->setStatus('38112.abc123', ['status' => 'open', 'actor' => 'ops@uengage.in']);
        $this->assertSame(
            ['status' => 'open', 'actor' => 'ops@uengage.in'],
            json_decode((string) $this->stub->lastCall()['body'], true)
        );
    }

    public function testSetStatusValidation(): void
    {
        foreach (
            [
                [['status' => 'closed', 'actor' => 'a'], 'setStatus.status'],
                [['status' => 'open', 'actor' => ' '], 'actor must be a non-empty string'],
                [['status' => 'open', 'actor' => 42], 'actor must be a non-empty string'],
                [['status' => 'resolved', 'actor' => 'a'], 'comment is required when status is resolved'],
                [['status' => 'muted', 'actor' => 'a', 'comment' => ' '], 'comment is required when status is muted'],
            ] as $case
        ) {
            try {
                $this->alerts->setStatus('38112.abc123', $case[0]);
                $this->fail('expected InvalidArgumentException for ' . json_encode($case[0]));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($case[1], $e->getMessage());
            }
        }
        $this->assertCount(0, $this->stub->getCalls());
    }

    public function testSetStatusOmitsActorForAUserToken(): void
    {
        $this->stub->pushResponse(200, '{"id":"38112.abc123","status":"resolved"}');
        $this->alerts->setStatus('38112.abc123', ['status' => 'resolved', 'comment' => 'Topped up']);
        $this->assertSame(
            ['status' => 'resolved', 'comment' => 'Topped up'],
            json_decode($this->stub->lastCall()['body'], true)
        );
    }

    public function testSetStatusThrowsApiExceptionOnError(): void
    {
        $this->stub->pushResponse(409, '{"error":"invalid_transition","message":"cannot change status from resolved to muted"}');
        try {
            $this->alerts->setStatus('38112.abc123', ['status' => 'muted', 'actor' => 'a', 'comment' => 'c']);
            $this->fail('expected AlertsApiException');
        } catch (AlertsApiException $e) {
            $this->assertSame(409, $e->getStatus());
            $this->assertSame('invalid_transition', $e->getErrorCode());
            $this->assertNull($e->getNewerAlertId());
        }
    }

    public function testReopenBlockedByANewerAlertSurfacesItsId(): void
    {
        $this->stub->pushResponse(
            409,
            '{"error":"newer_alert_open","id":"38112.wallet.low_balance.01K6B0000000000000000000CD"}'
        );
        try {
            $this->alerts->setStatus('38112.wallet.low_balance.01K6A0000000000000000000AB', [
                'status' => 'open',
                'actor' => 'ops@uengage.in',
                'comment' => 'not actually fixed',
            ]);
            $this->fail('expected AlertsApiException');
        } catch (AlertsApiException $e) {
            $this->assertSame(409, $e->getStatus());
            $this->assertSame(AlertsClient::ERROR_NEWER_ALERT_OPEN, $e->getErrorCode());
            $this->assertSame('38112.wallet.low_balance.01K6B0000000000000000000CD', $e->getNewerAlertId());
        }
        $this->assertSame(
            ['status' => 'open', 'actor' => 'ops@uengage.in', 'comment' => 'not actually fixed'],
            json_decode((string) $this->stub->lastCall()['body'], true),
            'a reopen may carry a comment'
        );
    }

    public function testErrorCodeIsNullForANonJsonBody(): void
    {
        $e = new AlertsApiException(502, 'Bad Gateway');
        $this->assertNull($e->getErrorCode());
        $this->assertNull($e->getErrorBody());
        $this->assertNull($e->getNewerAlertId());
    }
}
