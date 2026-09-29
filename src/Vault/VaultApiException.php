<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Vault;

use Uengage\PlatformSdk\Exceptions\ApiException;

/**
 * Non-2xx response from `/v1/vault/*` (or from the presigned upload target).
 *
 * Catch a subclass to branch on the kind of failure:
 *
 *   VaultDeniedException      403 - every denial; the reason is audited, not returned
 *   VaultNotFoundException    404
 *   VaultConflictException    409 - reference_conflict, version_conflict, document_not_uploaded, upload_mismatch, ...
 *   VaultValidationException  400 / 422 - unknown_type, bad input
 *   VaultUnavailableException 5xx, or the request never got an answer (status 0)
 *
 * {@see getErrorCode()} returns the body's machine-readable `error` field.
 * Error bodies never carry secret values.
 */
class VaultApiException extends ApiException
{
    /** @var string|null */
    private $errorCode;

    public function __construct(int $status, string $body)
    {
        parent::__construct('vault', $status, $body);
        $decoded = json_decode($body, true);
        $this->errorCode = is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])
            ? $decoded['error']
            : null;
    }

    /** The response's `error` code (`forbidden`, `reference_conflict`, ...), or null. */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The presigned storage POST failed (status 0: no answer). Error code
     * `upload_failed`; a VaultUnavailableException for 0 / 5xx. The document
     * stays `pending`.
     */
    public static function uploadFailed(int $status, string $body, string $documentId): self
    {
        $e = ($status === 0 || $status >= 500)
            ? new VaultUnavailableException($status, $body)
            : new self($status, $body);
        $e->errorCode = 'upload_failed';
        $e->message = $status === 0
            ? sprintf('vault: storage upload for document %s never got an answer (%s); it stays pending', $documentId, $body)
            : sprintf('vault: storage refused the upload for document %s (HTTP %d); it stays pending', $documentId, $status);
        return $e;
    }

    /**
     * Map a status to the most specific subclass.
     */
    public static function fromResponse(int $status, string $body): self
    {
        if ($status === 400 || $status === 422) {
            return new VaultValidationException($status, $body);
        }
        if ($status === 403) {
            return new VaultDeniedException($status, $body);
        }
        if ($status === 404) {
            return new VaultNotFoundException($status, $body);
        }
        if ($status === 409) {
            return new VaultConflictException($status, $body);
        }
        if ($status === 0 || $status >= 500) {
            return new VaultUnavailableException($status, $body);
        }
        return new self($status, $body);
    }
}
