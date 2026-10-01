<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

/**
 * 5xx, or a transport failure where no response arrived (status 0). Safe to retry reads.
 */
class VaultUnavailableException extends VaultApiException
{
}
