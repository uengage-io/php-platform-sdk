<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

/**
 * 403 - the token lacks the scope or tenant reach for this item. Every denial answers `{"error":"forbidden"}`; the specific reason goes to the audit log only.
 */
class VaultDeniedException extends VaultApiException
{
}
