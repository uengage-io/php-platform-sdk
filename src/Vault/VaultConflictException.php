<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

/**
 * 409 - `reference_conflict`, `version_conflict`, `document_not_uploaded`, `upload_not_found`, `upload_mismatch`, or a type that already exists.
 */
class VaultConflictException extends VaultApiException
{
}
