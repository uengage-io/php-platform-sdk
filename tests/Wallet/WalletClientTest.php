<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Wallet;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\RequestSigner;
use Uengage\PlatformSdk\Tests\Support\StubHttp;
use Uengage\PlatformSdk\Token\StaticBearerTokenSource;
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
}
