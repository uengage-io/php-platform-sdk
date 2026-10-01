<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Wallet;

use Uengage\PlatformSdk\Exceptions\ApiException;

/**
 * Non-2xx response from the wallet API.
 *
 * The wallet service returns a machine-readable `error` code in the JSON
 * body (`insufficient_balance`, `over_refund`, `invalid_reversal`,
 * `unresolvable_wallet`, `wallet_not_found`, …). Use {@see errorCode()} to
 * branch instead of matching on message text. {@see balanceMinor()} is set
 * on `insufficient_balance` (the current balance, in the wallet currency's
 * minor units).
 *
 * Credit-line codes worth branching on:
 *
 *   `credit_limit_reached` (409)      the charge would exceed the merchant's
 *                                     credit limit; nothing moved. Read
 *                                     {@see creditFigures()} — `availableMinor`
 *                                     of 0 means the limit is used up, and
 *                                     anything above 0 means this one charge
 *                                     is larger than what is left, which
 *                                     needs different copy.
 *   `credit_breakup_required` (400)   consumption is net of GST, so a charge
 *                                     on a credit-line merchant must send
 *                                     `breakup`.
 *   `credit_override_refused` (400)   `allowNegative` is not accepted on a
 *                                     credit line — it would bypass the limit.
 *   `credit_top_up_refused` (400)     a plain credit was sent for a
 *                                     credit-line merchant, who has no
 *                                     balance to top up. Credit is handed
 *                                     back by settling an invoice; a genuine
 *                                     refund is accepted, sent as
 *                                     `reversalOf` or `isRefund`.
 *   `credit_correction_reason_required` (400)
 *                                     a reversed invoice is being re-settled
 *                                     for DIFFERENT figures, which is a
 *                                     correction. It releases against the
 *                                     merchant's whole outstanding
 *                                     consumption rather than this invoice's
 *                                     amount, so pass `reason` and retry.
 *                                     The same figures need none.
 *   `credit_currency_conflict` (409)  a limit change named a currency the
 *                                     line is not denominated in. The code
 *                                     is fixed at creation — it sets the
 *                                     exponent the stored figures are
 *                                     already scaled by — so send the one
 *                                     the line carries; re-denominating is a
 *                                     migration.
 *   `credit_currency_mismatch` (409)  the credit line and the wallet the
 *                                     charge routed to are in different
 *                                     currencies, so `amountMinor` cannot be
 *                                     read as both. Not caller-fixable: the
 *                                     merchant's routing and their credit
 *                                     line disagree.
 *   `credit_line_not_found` (404)     the merchant is prepaid. Normal, not a
 *                                     failure.
 *   `credit_line_has_outstanding` (409)
 *                                     `enabled: false` refused — the merchant
 *                                     still owes for the credit line. Read
 *                                     `consumedMinor` from
 *                                     {@see creditFigures()} and tell the
 *                                     operator to settle first, or use
 *                                     `limitMinor: 0` if the intent was to
 *                                     block them rather than move them off.
 */
class WalletApiException extends ApiException
{
    public function __construct(int $status, string $body)
    {
        parent::__construct('wallet', $status, $body);
    }

    /** Machine-readable `error` code from the body, or null if absent/non-JSON. */
    public function errorCode(): ?string
    {
        $decoded = json_decode($this->getBody(), true);
        if (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
        return null;
    }

    /**
     * The credit-line figures carried on `credit_limit_reached` (HTTP 409),
     * in minor units. Empty array when absent or on any other error.
     *
     * Keys: `limitMinor`, `consumedMinor`, `availableMinor`,
     * `requestedMinor`, `gstAccruedMinor` — whichever the error carries.
     * `availableMinor` is what separates "the limit is
     * used up" from "this charge is larger than what is left" — the two
     * cases that share this code and need different merchant-facing copy.
     *
     * @return array
     */
    public function creditFigures(): array
    {
        $decoded = json_decode($this->getBody(), true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        $keys = ['limitMinor', 'consumedMinor', 'availableMinor', 'requestedMinor', 'gstAccruedMinor'];
        foreach ($keys as $key) {
            if (isset($decoded[$key]) && is_int($decoded[$key])) {
                $out[$key] = $decoded[$key];
            }
        }
        return $out;
    }

    /**
     * Current balance in the wallet currency's minor units — present only on
     * `insufficient_balance` (HTTP 409).
     */
    public function balanceMinor(): ?int
    {
        $decoded = json_decode($this->getBody(), true);
        if (is_array($decoded) && isset($decoded['balanceMinor']) && is_int($decoded['balanceMinor'])) {
            return $decoded['balanceMinor'];
        }
        return null;
    }
}
