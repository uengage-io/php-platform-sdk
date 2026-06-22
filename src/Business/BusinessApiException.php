<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Business;

use Uengage\PlatformSdk\Exceptions\ApiException;

class BusinessApiException extends ApiException
{
    public function __construct(int $status, string $body)
    {
        parent::__construct('business', $status, $body);
    }
}
