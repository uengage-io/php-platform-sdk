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
