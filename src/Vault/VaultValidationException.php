<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

/**
 * 400 or 422 - malformed input, or `unknown_type` for an unregistered type.
 */
class VaultValidationException extends VaultApiException
{
}
