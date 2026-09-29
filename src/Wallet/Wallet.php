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

    // ─── Credit line ────────────────────────────────────────────────────
    //
    // Resolved to the MERCHANT this wallet belongs to, not to the wallet:
    // all of a merchant's outlets share one credit line, so calling these
    // on an outlet handle and on its parent's handle is the same request.
    //
    // These send `businessId` alone and never `forceChildWallet` — which
    // wallet would have paid is not part of "who is the merchant?".

    /**
     * GET /v1/wallet/credit — everything the merchant widget renders:
     * limit, consumed, available, utilisation, band, estimated invoice.
     *
     * A prepaid merchant has no credit line, which comes back as HTTP 404
     * with `error: credit_line_not_found` — the normal state, so treat it
     * as "show the wallet balance instead" rather than as a failure.
     *
     * Requires `wallet.credit:read`.
     *
     * @return array
     */
    public function getCreditLine(): array
    {
        $path = '/v1/wallet/credit';
        $query = '?' . http_build_query(['businessId' => (string) $this->businessId]);
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $query));
        return $this->expectJson($response, 'getCreditLine');
    }

    /**
     * WHICH MODEL IS THIS MERCHANT ON — call this first.
     *
     * Returns `['mode' => 'prepaid'|'credit', 'balance' => [...], 'creditLine' => [...]]`,
     * with `creditLine` present only on the credit arm. A dashboard
     * settles which panels to render from `mode` rather than by catching
     * a 404.
     *
     * BALANCE FIRST, deliberately. `getBalance()` carries `source`, so for
     * any merchant who has a wallet at all the question is settled without
     * an exception — where calling `getCreditLine()` and catching its 404
     * would make the normal state of a prepaid merchant an error on every
     * page load. It also costs less where it matters: most merchants are
     * prepaid, and for them this is ONE request.
     *
     * A missing `source` means a wallet service older than the credit
     * line, which is prepaid by definition.
     *
     * WHAT THIS DOES NOT SWALLOW. `getBalance()` is not a guaranteed 200:
     * it throws `wallet_not_found` (404) when no wallet document exists
     * for the resolved identity and `unresolvable_wallet` (422) when
     * routing cannot name one. Both propagate, because both mean there is
     * genuinely nothing to render — answering `prepaid` with a zero
     * balance would invent a wallet.
     *
     * CAPABILITIES. `wallet.balance:read` covers a prepaid merchant; a
     * credit merchant needs `wallet.credit:read` as well, and a token
     * holding only the former gets a 403 from the second call. That is
     * deliberately not caught: falling back to `prepaid` would render the
     * frozen `wallet_balance` these merchants no longer spend from — a
     * confident wrong number in place of an error someone can fix by
     * widening the grant.
     *
     * @return array
     */
    public function getOverview(): array
    {
        $balance = $this->getBalance();
        $source = isset($balance['source']) ? $balance['source'] : null;
        if ($source !== 'credit_line') {
            return ['mode' => 'prepaid', 'balance' => $balance];
        }
        try {
            return [
                'mode' => 'credit',
                'balance' => $balance,
                'creditLine' => $this->getCreditLine(),
            ];
        } catch (WalletApiException $e) {
            // Ops can disable a line between the two calls. "prepaid" is
            // something a caller can act on; a thrown 404 is not.
            if ($e->errorCode() === 'credit_line_not_found') {
                return ['mode' => 'prepaid', 'balance' => $balance];
            }
            throw $e;
        }
    }

    /**
     * GET /v1/wallet/credit/events — the limit-change and settlement
     * trail for this merchant, newest first. Requires `wallet.credit:read`.
     *
     * The service returns 50 events by default — enough for a
     * recent-activity panel, not for a merchant's whole history on the
     * line. Pass `['limit' => 200]` to widen the page.
     *
     * `kind` is one of `limit_changed`, `settled` or `settlement_reversed`
     * — a reversal is its own row rather than an edit to the settlement
     * it undoes, so each row names its own actor and reason. A reversal
     * row also carries `cycle` (which reversal of that invoice, from 1),
     * since an invoice can be settled, reversed, re-settled and reversed
     * again. Settlement and reversal rows both carry `releasedNetMinor`
     * and `releasedGstMinor`.
     *
     * @param array $filter {limit?: int}
     * @return array list of events (the response envelope is unwrapped)
     */
    public function listCreditEvents(array $filter = array()): array
    {
        $path = '/v1/wallet/credit/events';
        $params = array('businessId' => (string) $this->businessId);
        if (isset($filter['limit'])) {
            $params['limit'] = (string) (int) $filter['limit'];
        }
        $query = '?' . http_build_query($params);
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $query));
        $decoded = $this->expectJson($response, 'listCreditEvents');
        return isset($decoded['events']) && is_array($decoded['events']) ? $decoded['events'] : [];
    }

    /**
     * PUT /v1/wallet/credit/limit — set this merchant's credit limit,
     * creating the credit line on first use.
     *
     * Consumption is never touched: available credit is always
     * `limit - consumed`, so raising a limit hands back exactly the
     * difference, and lowering it below what is consumed blocks charges
     * immediately. `limitMinor: 0` is meaningful — blocked, but still on
     * the credit line with its history intact.
     *
     * Idempotent on `referenceId`. `onBehalfOf` is REQUIRED so the audit
     * trail names the human, not just this service.
     *
     * Requires `wallet.credit:write`.
     *
     * @param array $input {referenceId: string, limitMinor: int, onBehalfOf: string,
     *                      enabled?: bool, reason?: string}
     * @return array {creditLine: array, idempotent: bool}
     */
    public function setCreditLimit(array $input): array
    {
        return $this->sendCredit('PUT', '/v1/wallet/credit/limit', $input, 'setCreditLimit');
    }

    /**
     * POST /v1/wallet/credit/settlements — release the credit an invoice
     * was holding, on payment IN FULL. There is no partial settlement.
     *
     * THE MERCHANT PAYS GROSS; THIS RELEASES NET. Pass the invoice's net
     * figure as `netMinor` — a Rs 5,900 invoice on Rs 5,000 of
     * consumption frees Rs 5,000, because the limit and consumption are
     * denominated net of GST. Passing the gross figure would hand the
     * merchant back 18% more credit than they ever consumed.
     *
     * Idempotent on `invoiceRef`, so a replayed webhook releases once.
     *
     * Requires `wallet.credit:settle`.
     *
     * @param array $input {invoiceRef: string, netMinor: int, gstMinor: int,
     *                      onBehalfOf: string, reason?: string}
     * @return array {creditLine: array, releasedNetMinor: int, releasedGstMinor: int,
     *                idempotent: bool}
     */
    public function settleInvoice(array $input): array
    {
        return $this->sendCredit('POST', '/v1/wallet/credit/settlements', $input, 'settleInvoice');
    }

    /**
     * POST /v1/wallet/credit/settlements/reversal — undo a settlement
     * recorded in error, re-holding exactly what it released.
     *
     * For a payment applied to the wrong merchant. Idempotent, and a
     * re-confirmed payment may settle the same invoice again afterwards.
     *
     * Requires `wallet.credit:settle`.
     *
     * @param array $input {invoiceRef: string, onBehalfOf: string, reason?: string}
     * @return array
     */
    public function reverseSettlement(array $input): array
    {
        return $this->sendCredit(
            'POST',
            '/v1/wallet/credit/settlements/reversal',
            $input,
            'reverseSettlement'
        );
    }

    /**
     * Shared body-carrying credit request. The business id is added here
     * rather than by each caller so it can never be omitted.
     *
     * `$input` is merged FIRST so `businessId` wins: a caller who passes
     * their own `businessId` is naming a different merchant than the one
     * this handle was opened for, and honouring it would move a limit or
     * settle an invoice against that other merchant while every log line
     * and error message still named this one. The handle's merchant is
     * the authoritative one, so the caller's key is overwritten rather
     * than obeyed.
     *
     * @param array $input
     * @return array
     */
    private function sendCredit(string $method, string $path, array $input, string $label): array
    {
        $payload = array_merge($input, ['businessId' => $this->businessId]);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new InvalidArgumentException($label . ': input is not JSON-encodable');
        }
        $response = $this->signer->send($method, $path, $this->signer->url($path), $body);
        return $this->expectJson($response, $label);
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
