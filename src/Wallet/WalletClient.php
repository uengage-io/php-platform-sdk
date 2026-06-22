<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Wallet;

use InvalidArgumentException;
use Uengage\PlatformSdk\Config;
use Uengage\PlatformSdk\Http\RequestSigner;

/**
 * Client for the platform wallet API (`/v1/wallet/*`).
 *
 * Mirrors the JS SDK's `platform.wallet` namespace. `getWallet(...)`
 * returns a {@see Wallet} handle bound to one business; all operations
 * hang off that handle. The call is I/O-free — the wallet is resolved
 * server-side on the first operation.
 */
class WalletClient
{
    const BUSINESS_ID_REGEX = '/^business:(\d+)$/';

    /** @var Config */
    private $config;

    /** @var RequestSigner */
    private $signer;

    public function __construct(Config $config, RequestSigner $signer)
    {
        $this->config = $config;
        $this->signer = $signer;
    }

    /**
     * Build a handle to a business wallet.
     *
     * @param array $opts {id: string ("business:<int>"), forceChildWallet?: bool}
     */
    public function getWallet(array $opts): Wallet
    {
        if (!isset($opts['id']) || !is_string($opts['id'])) {
            throw new InvalidArgumentException(
                'wallet.getWallet: opts.id is required, e.g. "business:8841"'
            );
        }
        if (!preg_match(self::BUSINESS_ID_REGEX, $opts['id'], $m)) {
            throw new InvalidArgumentException(sprintf(
                'wallet.getWallet: id must look like "business:<positive integer>" (got %s)',
                var_export($opts['id'], true)
            ));
        }
        $businessId = (int) $m[1];
        $forceChildWallet = isset($opts['forceChildWallet']) && $opts['forceChildWallet'] === true;
        return new Wallet($this->signer, $businessId, $forceChildWallet);
    }
}
