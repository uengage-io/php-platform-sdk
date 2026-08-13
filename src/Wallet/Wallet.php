<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Wallet;

use InvalidArgumentException;
use Uengage\PlatformSdk\Http\HttpResponse;
use Uengage\PlatformSdk\Http\RequestSigner;

/**
 * A handle to one business wallet. Cheap to create — does no I/O. The
 * wallet is resolved server-side on the first operation (so a routing
 * miss surfaces from the operation, not from construction).
 *
 * Mirrors the JS SDK's `wallet.getWallet(...)` handle: getBalance,
 * credit, debit, listTransactions, getTransaction. The wallet identity
 * (`businessId` + `forceChildWallet`) travels in the query (reads) or
 * the body (writes); the service resolves which wallet answers.
 *
 * Writes require a service token with `wallet.transactions:write`, reads
 * with `wallet.balance:read` / `wallet.transactions:read`. Non-2xx
 * responses throw {@see WalletApiException} (inspect ->errorCode()).
 */
class Wallet
{
    /** @var RequestSigner */
    private $signer;

    /** @var int */
    private $businessId;

    /** @var bool */
    private $forceChildWallet;

    public function __construct(RequestSigner $signer, int $businessId, bool $forceChildWallet)
    {
        $this->signer = $signer;
        $this->businessId = $businessId;
        $this->forceChildWallet = $forceChildWallet;
    }

    /**
     * GET /v1/wallet/balance — resolve the wallet and return its balance.
     *
     * @return array {balance: float, balanceMinor: int, currency: {code, symbol}}
     */
    public function getBalance(): array
    {
        $path = '/v1/wallet/balance';
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $this->query()));
        return $this->expectJson($response, 'getBalance');
    }

    /**
     * GET /v1/wallet/instance — wallet identity + currency, without the balance.
     *
     * @return array {wallet: {parentBusinessId, childBusinessId}, currency: {code, symbol}}
     */
    public function getInstance(): array
    {
        $path = '/v1/wallet/instance';
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $this->query()));
        return $this->expectJson($response, 'getInstance');
    }

    /**
     * The wallet's currency `{code, symbol}` (a slice of getInstance()).
     *
     * @return array {code: string, symbol: string}
     */
    public function getCurrency(): array
    {
        $instance = $this->getInstance();
        return isset($instance['currency']) && is_array($instance['currency'])
            ? $instance['currency']
            : [];
    }

    /**
     * POST /v1/wallet/transactions (type=credit). Pass `reversalOf` (a debit
     * id) for a refund. Idempotent on `referenceId`.
     *
     * @param array $input {referenceId, amountMinor, service, description, breakup?, tags?, reversalOf?}
     * @return array TransactionResult
     */
    public function credit(array $input): array
    {
        return $this->postTransaction('credit', $input);
    }

    /**
     * POST /v1/wallet/transactions (type=debit). Guarded against overdraw
     * (409 insufficient_balance) unless `allowNegative` is true. Idempotent
     * on `referenceId`.
     *
     * @param array $input {referenceId, amountMinor, service, description, breakup?, tags?, allowNegative?}
     * @return array TransactionResult
     */
    public function debit(array $input): array
    {
        return $this->postTransaction('debit', $input);
    }

    /**
     * GET /v1/wallet/transactions — newest-first, keyset-paginated.
     *
     * @param array $filter {type?: 'debit'|'credit'|'all', from?, to?, service?: int, referenceId?, tag?, cursor?, limit?: int}
     * @return array {transactions: array, nextCursor?: string}
     */
    public function listTransactions(array $filter = []): array
    {
        $params = ['businessId' => (string) $this->businessId];
        if ($this->forceChildWallet) {
            $params['forceChildWallet'] = 'true';
        }
        foreach (['type', 'from', 'to', 'referenceId', 'tag', 'cursor'] as $key) {
            if (isset($filter[$key]) && $filter[$key] !== null) {
                $params[$key] = (string) $filter[$key];
            }
        }
        if (isset($filter['service']) && $filter['service'] !== null) {
            $params['service'] = (string) $filter['service'];
        }
        if (isset($filter['limit']) && $filter['limit'] !== null) {
            if (!is_int($filter['limit']) || $filter['limit'] <= 0) {
                throw new InvalidArgumentException(
                    sprintf('wallet.listTransactions: limit must be a positive integer (got %s)', var_export($filter['limit'], true))
                );
            }
            $params['limit'] = (string) $filter['limit'];
        }
        $path = '/v1/wallet/transactions';
        $query = '?' . http_build_query($params);
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $query));
        return $this->expectJson($response, 'listTransactions');
    }

    /**
     * GET /v1/wallet/transactions/{id} — one transaction, scoped to this wallet.
     *
     * @return array WalletTransaction
     */
    public function getTransaction(string $id): array
    {
        if ($id === '') {
            throw new InvalidArgumentException('wallet.getTransaction: id is required');
        }
        $path = '/v1/wallet/transactions/' . rawurlencode($id);
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $this->query()));
        return $this->expectJson($response, 'getTransaction');
    }

    // ─── internals ───────────────────────────────────────────────────────

    private function postTransaction(string $type, array $input): array
    {
        if (!isset($input['referenceId']) || !is_string($input['referenceId']) || $input['referenceId'] === '') {
            throw new InvalidArgumentException(sprintf('wallet.%s: referenceId (string) is required', $type));
        }
        if (!isset($input['amountMinor']) || !is_int($input['amountMinor']) || $input['amountMinor'] <= 0) {
            throw new InvalidArgumentException(sprintf('wallet.%s: amountMinor (positive int) is required', $type));
        }
        $payload = array_merge($input, [
            'businessId' => $this->businessId,
            'forceChildWallet' => $this->forceChildWallet,
            'type' => $type,
        ]);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $path = '/v1/wallet/transactions';
        $response = $this->signer->send('POST', $path, $this->signer->url($path), $body);
        return $this->expectJson($response, $type);
    }

    /** Shared query string for the read endpoints (businessId [+ forceChildWallet]). */
    private function query(): string
    {
        $params = ['businessId' => (string) $this->businessId];
        if ($this->forceChildWallet) {
            $params['forceChildWallet'] = 'true';
        }
        return '?' . http_build_query($params);
    }

    /**
     * @return array
     */
    private function expectJson(HttpResponse $response, string $label): array
    {
        if (!$response->isOk()) {
            throw new WalletApiException($response->getStatus(), $response->getBody());
        }
        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new WalletApiException(
                $response->getStatus(),
                sprintf('wallet.%s: response was not valid JSON: %s', $label, $response->getBody())
            );
        }
        return $decoded;
    }
}
