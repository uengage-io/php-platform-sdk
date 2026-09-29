<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Wallet;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;
use Uengage\PlatformSdk\Wallet\Services;
use Uengage\PlatformSdk\Wallet\WalletApiException;
use Uengage\PlatformSdk\Wallet\WalletClient;

class WalletClientTest extends TestCase
{
    /** @var StubHttp */
    private $stub;

    /** @var WalletClient */
    private $wallet;

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
        $this->wallet = new WalletClient($config, $signer);
    }

    // ─── getWallet validation ───────────────────────────────────────────

    public function testGetWalletRejectsMissingId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->wallet->getWallet([]);
    }

    public function testGetWalletRejectsMalformedId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->wallet->getWallet(['id' => 'merchant:8841']);
    }

    // ─── balance ─────────────────────────────────────────────────────────

    public function testGetBalanceGetsBalancePathWithBusinessId(): void
    {
        $this->stub->pushJson(200, [
            'balance' => 14500.5,
            'balanceMinor' => 1450050,
            'currency' => ['code' => 'INR', 'symbol' => '₹'],
        ]);
        $result = $this->wallet->getWallet(['id' => 'business:8841'])->getBalance();

        $this->assertSame(1450050, $result['balanceMinor']);
        $call = $this->stub->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertStringContainsString('/v1/wallet/balance?businessId=8841', $call['url']);
        $this->assertStringNotContainsString('forceChildWallet', $call['url']);
    }

    public function testGetBalanceAddsForceChildWallet(): void
    {
        $this->stub->pushJson(200, ['balance' => 0, 'balanceMinor' => 0, 'currency' => ['code' => 'INR', 'symbol' => '₹']]);
        $this->wallet->getWallet(['id' => 'business:8841', 'forceChildWallet' => true])->getBalance();
        $this->assertStringContainsString('forceChildWallet=true', $this->stub->lastCall()['url']);
    }

    public function testGetInstanceGetsInstancePath(): void
    {
        $this->stub->pushJson(200, [
            'wallet' => ['parentBusinessId' => '8841', 'childBusinessId' => '0'],
            'currency' => ['code' => 'INR', 'symbol' => '₹'],
        ]);
        $currency = $this->wallet->getWallet(['id' => 'business:8841'])->getCurrency();

        $this->assertSame(['code' => 'INR', 'symbol' => '₹'], $currency);
        $this->assertStringContainsString('/v1/wallet/instance?businessId=8841', $this->stub->lastCall()['url']);
    }

    // ─── credit / debit ──────────────────────────────────────────────────

    public function testCreditPostsTransactionBody(): void
    {
        $this->stub->pushJson(201, ['id' => 'abc', 'idempotent' => false, 'type' => 'credit']);
        $this->wallet->getWallet(['id' => 'business:8841'])->credit([
            'referenceId' => 'ref-1',
            'amountMinor' => 1180,
            'service' => 0,
            'description' => 'topup',
        ]);
        $call = $this->stub->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('https://api.test/v1/wallet/transactions', $call['url']);
        $this->assertStringContainsString('"type":"credit"', $call['body']);
        $this->assertStringContainsString('"businessId":8841', $call['body']);
    }

    public function testDebitSetsType(): void
    {
        $this->stub->pushJson(201, ['id' => 'abc', 'type' => 'debit']);
        $this->wallet->getWallet(['id' => 'business:8841'])->debit([
            'referenceId' => 'ref-2',
            'amountMinor' => 500,
            'service' => 5,
            'description' => 'charge',
        ]);
        $this->assertStringContainsString('"type":"debit"', $this->stub->lastCall()['body']);
    }

    public function testCreditRejectsMissingReferenceId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->wallet->getWallet(['id' => 'business:8841'])->credit([
            'amountMinor' => 100,
            'service' => 0,
            'description' => 'x',
        ]);
    }

    public function testDebitRejectsNonPositiveAmount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->wallet->getWallet(['id' => 'business:8841'])->debit([
            'referenceId' => 'ref-3',
            'amountMinor' => 0,
            'service' => 0,
            'description' => 'x',
        ]);
    }

    // ─── history ─────────────────────────────────────────────────────────

    public function testListTransactionsBuildsQuery(): void
    {
        $this->stub->pushJson(200, ['transactions' => [], 'nextCursor' => null]);
        $this->wallet->getWallet(['id' => 'business:8841'])->listTransactions([
            'type' => 'debit',
            'service' => 5,
            'limit' => 10,
        ]);
        $url = $this->stub->lastCall()['url'];
        $this->assertStringContainsString('businessId=8841', $url);
        $this->assertStringContainsString('type=debit', $url);
        $this->assertStringContainsString('service=5', $url);
        $this->assertStringContainsString('limit=10', $url);
    }

    public function testListTransactionsRejectsNonPositiveLimit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->wallet->getWallet(['id' => 'business:8841'])->listTransactions(['limit' => 0]);
    }

    public function testGetTransactionPathAndScope(): void
    {
        $this->stub->pushJson(200, ['id' => 'deadbeef', 'type' => 'credit']);
        $this->wallet->getWallet(['id' => 'business:8841'])->getTransaction('deadbeef');
        $url = $this->stub->lastCall()['url'];
        $this->assertStringContainsString('/v1/wallet/transactions/deadbeef?businessId=8841', $url);
    }

    // ─── errors ──────────────────────────────────────────────────────────

    public function testNon2xxThrowsWalletApiExceptionWithErrorCode(): void
    {
        $this->stub->pushJson(409, ['error' => 'insufficient_balance', 'balanceMinor' => 500]);
        try {
            $this->wallet->getWallet(['id' => 'business:8841'])->debit([
                'referenceId' => 'ref-4',
                'amountMinor' => 100000,
                'service' => 0,
                'description' => 'overdraw',
            ]);
            $this->fail('expected WalletApiException');
        } catch (WalletApiException $e) {
            $this->assertSame(409, $e->getStatus());
            $this->assertSame('insufficient_balance', $e->errorCode());
            $this->assertSame(500, $e->balanceMinor());
        }
    }

    // ─── legacy-ledger passthroughs ──────────────────────────────────────

    public function testDebitForwardsFlashPassthroughFields(): void
    {
        $this->stub->pushJson(201, ['id' => 'abc', 'type' => 'debit']);
        $this->wallet->getWallet(['id' => 'business:8841'])->debit([
            'referenceId' => 'flash:task:9912',
            'amountMinor' => 8000,
            'service' => Services::FLASH_DELIVERY,
            'description' => 'delivery',
            'taskId' => 'TASK-9912',
            'rto' => true,
            'units' => 2,
            'serviceBaseCost' => 38.5,
            'occurredAt' => '2026-07-01 10:15:00',
            'updatedBy' => 'PetPooja',
        ]);
        $body = json_decode($this->stub->lastCall()['body'], true);

        $this->assertSame('TASK-9912', $body['taskId']);
        $this->assertTrue($body['rto']);
        $this->assertSame(2, $body['units']);
        $this->assertSame(38.5, $body['serviceBaseCost']);
        $this->assertSame('2026-07-01 10:15:00', $body['occurredAt']);
        $this->assertSame('PetPooja', $body['updatedBy']);
        $this->assertSame(8841, $body['businessId']);
    }

    public function testCreditForwardsIsRefundAndEmptyPaymentId(): void
    {
        $this->stub->pushJson(201, ['id' => 'abc', 'type' => 'credit']);
        $this->wallet->getWallet(['id' => 'business:8841'])->credit([
            'referenceId' => 'flash:refund:1',
            'amountMinor' => 2000,
            'service' => Services::FLASH_DELIVERY,
            'description' => 'buffer refund',
            'isRefund' => true,
            'paymentId' => '',
        ]);
        $body = json_decode($this->stub->lastCall()['body'], true);

        $this->assertTrue($body['isRefund']);
        // '' is meaningful to the ledger (uniq_payment_id is filtered on
        // `payment_id > ''`), so it must survive as a present empty string.
        $this->assertArrayHasKey('paymentId', $body);
        $this->assertSame('', $body['paymentId']);
    }

    public function testInputCannotOverrideTheFixedKeys(): void
    {
        $this->stub->pushJson(201, ['id' => 'abc', 'type' => 'debit']);
        $this->wallet->getWallet(['id' => 'business:8841'])->debit([
            'referenceId' => 'ref-5',
            'amountMinor' => 500,
            'service' => 5,
            'description' => 'charge',
            // A caller cannot smuggle a different wallet or type past the
            // handle: the fixed keys are merged last.
            'businessId' => 999,
            'type' => 'credit',
        ]);
        $body = json_decode($this->stub->lastCall()['body'], true);

        $this->assertSame(8841, $body['businessId']);
        $this->assertSame('debit', $body['type']);
    }

    // ─── explicit wallet override ────────────────────────────────────────

    public function testWalletOverrideReplacesBusinessId(): void
    {
        $this->stub->pushJson(201, ['id' => 'abc', 'type' => 'debit']);
        $this->wallet->getWallet(['id' => 'business:8841', 'forceChildWallet' => true])->debit([
            'referenceId' => 'admin:adjust:1',
            'amountMinor' => 8000,
            'service' => Services::FLASH_DELIVERY,
            'description' => 'manual adjustment',
            'wallet' => ['parentBusinessId' => '100', 'childBusinessId' => '500'],
        ]);
        $body = json_decode($this->stub->lastCall()['body'], true);

        $this->assertSame(['parentBusinessId' => '100', 'childBusinessId' => '500'], $body['wallet']);
        // Sending both identities is a 400 server-side (exactly-one-of), so
        // neither the handle's business id nor its forceChildWallet rides along.
        $this->assertArrayNotHasKey('businessId', $body);
        $this->assertArrayNotHasKey('forceChildWallet', $body);
        $this->assertSame('debit', $body['type']);
    }

    // ─── service catalog ─────────────────────────────────────────────────

    public function testServicesCoversTheServerCatalog(): void
    {
        $this->assertSame(0, Services::RECHARGE);
        $this->assertSame(1, Services::SMS_CAMPAIGN);
        $this->assertSame(4, Services::WHATSAPP_CAMPAIGN);
        $this->assertSame(5, Services::FLASH_DELIVERY);
        $this->assertSame(10, Services::EMAIL);
        $this->assertSame(21, Services::PRISM_AI);
        $this->assertSame(40, Services::WHATSAPP_ALERT);
        $this->assertSame(50, Services::SMS_ALERT);
    }

    public function testServicesDeprecatedAliasesKeepTheirIds(): void
    {
        $this->assertSame(Services::SMS_CAMPAIGN, Services::SMS);
        $this->assertSame(Services::WHATSAPP_CAMPAIGN, Services::WHATSAPP);
        $this->assertSame(Services::WHATSAPP_ALERT, Services::ALERT);
    }

    public function testServicesNamesAnIdAndFallsBackToUnknown(): void
    {
        $this->assertSame('SMS_ALERT', Services::name(50));
        $this->assertSame('UNKNOWN', Services::name(9999));
        $this->assertTrue(Services::isKnown(21));
        $this->assertFalse(Services::isKnown(9999));
        // The alias ids resolve to the canonical name, and NAMES holds one
        // entry per id rather than one per constant.
        $this->assertSame('WHATSAPP_ALERT', Services::name(Services::ALERT));
        $this->assertCount(8, Services::NAMES);
    }

    // ─── Credit line ────────────────────────────────────────────────────

    /** @return array */
    private function sampleCreditLine(): array
    {
        return [
            'merchantId' => '1200',
            'enabled' => true,
            'limit' => ['amount' => 100000, 'amountMinor' => 10000000],
            'consumed' => ['amount' => 62840, 'amountMinor' => 6284000],
            'available' => ['amount' => 37160, 'amountMinor' => 3716000],
            'utilisationPercent' => 62.84,
            'band' => 'normal',
            'currency' => ['code' => 'INR', 'symbol' => '₹'],
            'updatedAt' => '2026-09-01 11:04:21',
        ];
    }

    public function testGetCreditLineResolvesTheMerchantFromTheBusinessId(): void
    {
        $this->stub->pushJson(200, $this->sampleCreditLine());
        $out = $this->wallet->getWallet(['id' => 'business:8841'])->getCreditLine();
        $this->assertSame('1200', $out['merchantId']);
        $call = $this->stub->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertStringContainsString('/v1/wallet/credit?businessId=8841', $call['url']);
    }

    public function testCreditCallsNeverSendForceChildWallet(): void
    {
        // The credit line belongs to the merchant; which wallet would have
        // paid is not part of that question.
        $this->stub->pushJson(200, $this->sampleCreditLine());
        $this->wallet
            ->getWallet(['id' => 'business:8841', 'forceChildWallet' => true])
            ->getCreditLine();
        $this->assertStringNotContainsString('forceChildWallet', $this->stub->lastCall()['url']);
    }

    public function testPrepaidMerchantSurfacesCreditLineNotFound(): void
    {
        $this->stub->pushJson(404, ['error' => 'credit_line_not_found', 'message' => 'nope']);
        try {
            $this->wallet->getWallet(['id' => 'business:1200'])->getCreditLine();
            $this->fail('expected WalletApiException');
        } catch (WalletApiException $e) {
            $this->assertSame('credit_line_not_found', $e->errorCode());
            $this->assertSame(404, $e->getStatus());
        }
    }

    public function testListCreditEventsUnwrapsTheEnvelope(): void
    {
        $this->stub->pushJson(200, ['events' => [
            [
                'kind' => 'limit_changed',
                'ref' => 'admin:limit:1',
                'limitAfterMinor' => 10000000,
                'actor' => 'service:ops-panel',
                'onBehalfOf' => 'user:4471',
                'recordedAt' => '2026-04-10 09:00:00',
            ],
        ]]);
        $out = $this->wallet->getWallet(['id' => 'business:1200'])->listCreditEvents();
        $this->assertCount(1, $out);
        $this->assertSame('limit_changed', $out[0]['kind']);
    }

    public function testSetCreditLimitPutsTheBusinessIdWithTheBody(): void
    {
        $this->stub->pushJson(200, ['creditLine' => $this->sampleCreditLine(), 'idempotent' => false]);
        $this->wallet->getWallet(['id' => 'business:8841'])->setCreditLimit([
            'referenceId' => 'admin:limit:1',
            'limitMinor' => 15000000,
            'onBehalfOf' => 'user:4471',
        ]);
        $call = $this->stub->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertStringContainsString('/v1/wallet/credit/limit', $call['url']);
        $body = json_decode($call['body'], true);
        $this->assertSame(8841, $body['businessId']);
        $this->assertSame(15000000, $body['limitMinor']);
        $this->assertSame('user:4471', $body['onBehalfOf']);
    }

    /**
     * The handle's merchant wins over one passed in the body. Merged the
     * other way round and a caller who passes `businessId` — copying a
     * curl example, say — moves a DIFFERENT merchant's limit while every
     * log line and error message still names the handle's. The write
     * succeeds and lands in the wrong place, which is the failure mode
     * with no symptom.
     */
    public function testABusinessIdInTheBodyCannotRetargetTheWrite(): void
    {
        $this->stub->pushJson(200, ['creditLine' => $this->sampleCreditLine(), 'idempotent' => false]);
        $this->wallet->getWallet(['id' => 'business:8841'])->setCreditLimit([
            'businessId' => 999,
            'referenceId' => 'admin:limit:1',
            'limitMinor' => 15000000,
            'onBehalfOf' => 'user:4471',
        ]);
        $body = json_decode($this->stub->lastCall()['body'], true);
        $this->assertSame(8841, $body['businessId']);
    }

    public function testTheSameHoldsForSettleInvoiceAndReversal(): void
    {
        foreach (array('settleInvoice', 'reverseSettlement') as $method) {
            $this->stub->pushJson(200, [
                'creditLine' => $this->sampleCreditLine(),
                'releasedNetMinor' => 500000,
                'releasedGstMinor' => 90000,
                'idempotent' => false,
            ]);
            $this->wallet->getWallet(['id' => 'business:8841'])->{$method}([
                'businessId' => 999,
                'invoiceRef' => 'invoice:2026-04',
                'netMinor' => 500000,
                'gstMinor' => 90000,
                'onBehalfOf' => 'user:9',
            ]);
            $body = json_decode($this->stub->lastCall()['body'], true);
            $this->assertSame(8841, $body['businessId'], $method . ' must not be retargetable');
        }
    }

    public function testListCreditEventsAcceptsALimit(): void
    {
        $this->stub->pushJson(200, ['events' => []]);
        $this->wallet->getWallet(['id' => 'business:1200'])->listCreditEvents(['limit' => 200]);
        $this->assertStringContainsString('limit=200', $this->stub->lastCall()['url']);
    }

    public function testSettleInvoiceSendsTheNetFigure(): void
    {
        $this->stub->pushJson(200, [
            'creditLine' => $this->sampleCreditLine(),
            'releasedNetMinor' => 500000,
            'releasedGstMinor' => 90000,
            'idempotent' => false,
        ]);
        $out = $this->wallet->getWallet(['id' => 'business:1200'])->settleInvoice([
            'invoiceRef' => 'invoice:2026-04',
            'netMinor' => 500000,
            'gstMinor' => 90000,
            'onBehalfOf' => 'user:9',
        ]);
        $this->assertSame(500000, $out['releasedNetMinor']);
        $body = json_decode($this->stub->lastCall()['body'], true);
        $this->assertSame(500000, $body['netMinor']);
    }

    public function testReverseSettlementPostsToTheReversalPath(): void
    {
        $this->stub->pushJson(200, [
            'creditLine' => $this->sampleCreditLine(),
            'releasedNetMinor' => 500000,
            'releasedGstMinor' => 90000,
            'idempotent' => false,
        ]);
        $this->wallet->getWallet(['id' => 'business:1200'])->reverseSettlement([
            'invoiceRef' => 'invoice:2026-04',
            'onBehalfOf' => 'user:9',
        ]);
        $this->assertStringContainsString(
            '/v1/wallet/credit/settlements/reversal',
            $this->stub->lastCall()['url']
        );
    }

    public function testGetCreditUtilisationSitsOnTheClient(): void
    {
        $this->stub->pushJson(200, ['merchants' => [$this->sampleCreditLine()], 'total' => 7]);
        $out = $this->wallet->getCreditUtilisation(['band' => 'warning', 'minUtilisationBps' => 8000]);
        $this->assertSame(7, $out['total']);
        $url = $this->stub->lastCall()['url'];
        $this->assertStringContainsString('minUtilisationBps=8000', $url);
        $this->assertStringContainsString('band=warning', $url);
    }

    public function testGetCreditUtilisationWithNoFilterSendsNoQueryString(): void
    {
        $this->stub->pushJson(200, ['merchants' => [], 'total' => 0]);
        $this->wallet->getCreditUtilisation();
        $this->assertStringNotContainsString('?', $this->stub->lastCall()['url']);
    }

    public function testCreditLimitReachedCarriesTheFiguresThatSeparateTheTwoCases(): void
    {
        // Credit remains, so the merchant-facing copy is "this charge is
        // too large", not "your credit limit is reached".
        $this->stub->pushJson(409, [
            'error' => 'credit_limit_reached',
            'limitMinor' => 10000000,
            'consumedMinor' => 9950000,
            'availableMinor' => 50000,
            'requestedMinor' => 100000,
        ]);
        try {
            $this->wallet->getWallet(['id' => 'business:8841'])->debit([
                'referenceId' => 'flash:task:7787',
                'amountMinor' => 1180,
                'service' => Services::FLASH_DELIVERY,
                'description' => 'delivery',
                'breakup' => ['subTotalMinor' => 1000, 'gstMinor' => 180, 'gstRate' => 18],
            ]);
            $this->fail('expected WalletApiException');
        } catch (WalletApiException $e) {
            $this->assertSame('credit_limit_reached', $e->errorCode());
            $figures = $e->creditFigures();
            $this->assertSame(50000, $figures['availableMinor']);
            $this->assertSame(100000, $figures['requestedMinor']);
        }
    }

    public function testCreditFiguresIsEmptyOnUnrelatedErrors(): void
    {
        $this->stub->pushJson(409, ['error' => 'insufficient_balance', 'balanceMinor' => 5000]);
        try {
            $this->wallet->getWallet(['id' => 'business:8841'])->debit([
                'referenceId' => 'r1',
                'amountMinor' => 1180,
                'service' => Services::FLASH_DELIVERY,
                'description' => 'd',
            ]);
            $this->fail('expected WalletApiException');
        } catch (WalletApiException $e) {
            $this->assertSame([], $e->creditFigures());
            $this->assertSame(5000, $e->balanceMinor());
        }
    }

    /** @return array */
    private function balancePayload(string $source = null): array
    {
        $out = [
            'balance' => 37160, 'balanceMinor' => 3716000,
            'currency' => ['code' => 'INR', 'symbol' => '₹'],
        ];
        if ($source !== null) {
            $out['source'] = $source;
        }
        return $out;
    }

    public function testGetOverviewSettlesPrepaidInOneRequest(): void
    {
        $this->stub->pushJson(200, $this->balancePayload('wallet_balance'));
        $out = $this->wallet->getWallet(['id' => 'business:1200'])->getOverview();
        $this->assertSame('prepaid', $out['mode']);
        $this->assertArrayNotHasKey('creditLine', $out);
        // One call, and /credit was never touched.
        $this->assertStringContainsString('/v1/wallet/balance', $this->stub->lastCall()['url']);
    }

    public function testGetOverviewReturnsTheCreditLineForACreditMerchant(): void
    {
        $this->stub->pushJson(200, $this->balancePayload('credit_line'));
        $this->stub->pushJson(200, $this->sampleCreditLine());
        $out = $this->wallet->getWallet(['id' => 'business:8841'])->getOverview();
        $this->assertSame('credit', $out['mode']);
        $this->assertSame(10000000, $out['creditLine']['limit']['amountMinor']);
        $this->assertStringContainsString('/v1/wallet/credit', $this->stub->lastCall()['url']);
    }

    public function testGetOverviewReadsAPreCreditServiceAsPrepaid(): void
    {
        // No `source` key at all — an older wallet deploy.
        $this->stub->pushJson(200, $this->balancePayload());
        $out = $this->wallet->getWallet(['id' => 'business:1200'])->getOverview();
        $this->assertSame('prepaid', $out['mode']);
    }

    public function testGetOverviewFallsBackWhenTheLineIsDisabledMidFlight(): void
    {
        $this->stub->pushJson(200, $this->balancePayload('credit_line'));
        $this->stub->pushJson(404, ['error' => 'credit_line_not_found', 'message' => 'gone']);
        $out = $this->wallet->getWallet(['id' => 'business:8841'])->getOverview();
        $this->assertSame('prepaid', $out['mode']);
    }

    public function testGetOverviewStillThrowsOnARealFailure(): void
    {
        $this->stub->pushJson(200, $this->balancePayload('credit_line'));
        $this->stub->pushJson(500, ['error' => 'boom']);
        $this->expectException(WalletApiException::class);
        $this->wallet->getWallet(['id' => 'business:8841'])->getOverview();
    }

    public function testTakingAMerchantOffWhileTheyOweSurfacesTheOutstanding(): void
    {
        $this->stub->pushJson(409, [
            'error' => 'credit_line_has_outstanding',
            'message' => 'settle it first',
            'consumedMinor' => 4000000,
            'gstAccruedMinor' => 720000,
        ]);
        try {
            $this->wallet->getWallet(['id' => 'business:1200'])->setCreditLimit([
                'referenceId' => 'admin:off:1',
                'limitMinor' => 10000000,
                'enabled' => false,
                'onBehalfOf' => 'user:4471',
            ]);
            $this->fail('expected WalletApiException');
        } catch (WalletApiException $e) {
            $this->assertSame('credit_line_has_outstanding', $e->errorCode());
            $f = $e->creditFigures();
            $this->assertSame(4000000, $f['consumedMinor']);
            $this->assertSame(720000, $f['gstAccruedMinor']);
        }
    }
}
