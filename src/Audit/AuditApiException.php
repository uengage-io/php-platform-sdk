<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Audit;

use Uengage\PlatformSdk\Exceptions\ApiException;

class AuditApiException extends ApiException
{
    public function __construct(int $status, string $body)
    {
        parent::__construct('audit', $status, $body);
    }
}
