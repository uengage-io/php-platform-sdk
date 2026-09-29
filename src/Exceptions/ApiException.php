<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Exceptions;

use RuntimeException;

/**
 * Base class for non-2xx responses from a platform API. Subclasses per
 * namespace (ZonesApiException, BusinessApiException, AuditApiException)
 * narrow the type at the catch site.
 *
 * `getStatus()` is the HTTP status; `getBody()` is the raw response body
 * (may be JSON, may be plain text - the SDK does not parse beyond what
 * the per-namespace client needs).
 */
class ApiException extends RuntimeException
{
    /** @var int */
    private $status;

    /** @var string */
    private $body;

    public function __construct(string $namespace, int $status, string $body)
    {
        parent::__construct(sprintf('%s API %d: %s', $namespace, $status, $body));
        $this->status = $status;
        $this->body = $body;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
