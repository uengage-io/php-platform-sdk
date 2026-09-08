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

    /**
     * GET /v1/wallet/credit/utilisation — every merchant on the credit
     * line, most-utilised first. The ops dashboard feed.
     *
     * On the client rather than on a wallet handle because it is the one
     * wallet operation not scoped to a business, and it carries its own
     * capability (`wallet.credit:list`) for the same reason: it is a
     * materially broader grant than reading one merchant's line.
     *
     * @param array $filter {minUtilisationBps?: int, band?: string, offset?: int, limit?: int}
     * @return array {merchants: array, total: int}
     */
    public function getCreditUtilisation(array $filter = []): array
    {
        $params = [];
        if (isset($filter['minUtilisationBps'])) {
            $params['minUtilisationBps'] = (string) (int) $filter['minUtilisationBps'];
        }
        if (isset($filter['band'])) {
            $params['band'] = (string) $filter['band'];
        }
        if (isset($filter['offset'])) {
            $params['offset'] = (string) (int) $filter['offset'];
        }
        if (isset($filter['limit'])) {
            $params['limit'] = (string) (int) $filter['limit'];
        }
        $path = '/v1/wallet/credit/utilisation';
        $query = $params === [] ? '' : '?' . http_build_query($params);
        $response = $this->signer->send('GET', $path, $this->signer->url($path, $query));
        if (!$response->isOk()) {
            throw new WalletApiException($response->getStatus(), $response->getBody());
        }
        $decoded = $response->json();
        if (!is_array($decoded)) {
            throw new WalletApiException(
                $response->getStatus(),
                'getCreditUtilisation: response body was not a JSON object'
            );
        }
        return $decoded;
    }
}
