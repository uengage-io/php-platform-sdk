<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Wallet;

/**
 * Chargeable services. The value is the legacy `service_id` stored on
 * every ledger entry (so existing reports/invoicing keep working);
 * callers reference the named constant instead of hardcoding the number.
 *
 * Mirrors the JS SDK's `Services` map and the server's catalog
 * (`services/wallet/src/services/service-catalog.ts`), which is what
 * names an id in `service.name` on a transaction read.
 *
 *     $wallet->debit([
 *         'referenceId' => 'flash:task:9912',
 *         'amountMinor' => 8000,
 *         'service'     => Services::FLASH_DELIVERY,
 *         'description' => 'delivery',
 *     ]);
 */
final class Services
{
    const RECHARGE = 0;
    const SMS_CAMPAIGN = 1;
    const WHATSAPP_CAMPAIGN = 4;
    const FLASH_DELIVERY = 5;
    const EMAIL = 10;
    const PRISM_AI = 21;
    const WHATSAPP_ALERT = 40;
    const SMS_ALERT = 50;

    // ── deprecated aliases ──
    // Kept so existing callers keep working. They were ambiguous once 50
    // (transactional SMS) and 21 landed: `SMS` did not say whether it
    // meant a campaign send or an alert. Same numbers, so switching to
    // the canonical name writes an identical ledger entry.

    /** @deprecated ambiguous — use self::SMS_CAMPAIGN (same id). */
    const SMS = 1;

    /** @deprecated ambiguous — use self::WHATSAPP_CAMPAIGN (same id). */
    const WHATSAPP = 4;

    /** @deprecated ambiguous — use self::WHATSAPP_ALERT (same id). */
    const ALERT = 40;

    /**
     * Canonical id => name. Deliberately excludes the deprecated aliases:
     * an id has exactly one name, and this is the map that names it.
     *
     * @var array<int, string>
     */
    const NAMES = [
        self::RECHARGE => 'RECHARGE',
        self::SMS_CAMPAIGN => 'SMS_CAMPAIGN',
        self::WHATSAPP_CAMPAIGN => 'WHATSAPP_CAMPAIGN',
        self::FLASH_DELIVERY => 'FLASH_DELIVERY',
        self::EMAIL => 'EMAIL',
        self::PRISM_AI => 'PRISM_AI',
        self::WHATSAPP_ALERT => 'WHATSAPP_ALERT',
        self::SMS_ALERT => 'SMS_ALERT',
    ];

    /**
     * Name for a service id, or 'UNKNOWN' — matching the server, which
     * reports an unrecognised id the same way rather than failing a read.
     */
    public static function name(int $id): string
    {
        return isset(self::NAMES[$id]) ? self::NAMES[$id] : 'UNKNOWN';
    }

    /** Is this a service id the catalog knows? */
    public static function isKnown(int $id): bool
    {
        return isset(self::NAMES[$id]);
    }

    /** Not instantiable — a constant holder, not an object. */
    private function __construct()
    {
    }
}
