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
 * the body (writes); the service resolves which wallet answers. A write
 * may instead name the wallet outright with
 * `'wallet' => ['parentBusinessId' => …, 'childBusinessId' => …]`, which
 * bypasses routing for that call.
 *
 * Service ids come from {@see Services}.
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
     * POST /v1/wallet/transactions (type=credit). Idempotent on
     * `referenceId`.
     *
     * Two kinds of refund: `reversalOf` (a debit id) links the credit to
     * that debit and is capped by it, while `isRefund` marks a refund
     * computed independently of any one debit — no link, no cap.
     *
     * `paymentId` accepts the empty string and sends it through as-is.
     *
     * @param array $input {referenceId, amountMinor, service, description,
     *     breakup?, tags?, reversalOf?, isRefund?: bool, paymentId?: string,
     *     wallet?: {parentBusinessId, childBusinessId},
     *     taskId?: string, units?: int, serviceBaseCost?: float,
     *     occurredAt?: string, updatedBy?: string}
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
     * The legacy-ledger passthroughs (`taskId`, `rto`, `units`,
     * `serviceBaseCost`, `occurredAt`, `updatedBy`) are written as
     * TOP-LEVEL ledger fields, not under `tags`. `taskId` is stored as both
     * `task_id` and `transaction_order_id`; on a DEBIT, supplying it also
     * materialises `units` (default 1) and `rto` (default false) so the
     * indexed {task_id, transaction_type, rto} filter matches the row.
     * Credits are not defaulted (the legacy writer's credit row carries
     * neither key); an explicit `units` is honoured on either type.
     *
     * `occurredAt` is IST `YYYY-MM-DD HH:mm:ss` and dates the CHARGE
     * (`inserted_date`), not the write — for backfills and replays. It may
     * not be future-dated or backdated more than 90 days.
     *
     * `wallet` names the wallet outright, bypassing `bId_deduction`
     * routing for this one call; the handle's business id is then not sent.
     * For a child wallet the service asserts the claimed
     * `parentBusinessId` against the wallet document instead of filtering
     * on it, so a wrong one throws with errorCode
     * `wallet_identity_mismatch` (409) rather than charging the right
     * wallet under the wrong identity.
     *
     * @param array $input {referenceId, amountMinor, service, description,
     *     breakup?, tags?, allowNegative?, rto?: bool,
     *     wallet?: {parentBusinessId, childBusinessId},
     *     taskId?: string, units?: int, serviceBaseCost?: float,
     *     occurredAt?: string, updatedBy?: string}
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
        // The wallet identity is exactly-one-of server-side, so an explicit
        // `wallet` override REPLACES this handle's business id rather than
        // travelling alongside it — sending both is a 400. Every other
        // caller key passes straight through; the fixed keys are merged
        // last so they cannot be overridden from $input.
        if (isset($input['wallet'])) {
            $payload = array_merge($input, ['type' => $type]);
        } else {
            $payload = array_merge($input, [
                'businessId' => $this->businessId,
                'forceChildWallet' => $this->forceChildWallet,
                'type' => $type,
            ]);
        }
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
