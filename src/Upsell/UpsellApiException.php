<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Upsell;

use Uengage\PlatformSdk\Exceptions\ApiException;

class UpsellApiException extends ApiException
{
    public function __construct(int $status, string $body)
    {
        parent::__construct('upsell', $status, $body);
    }
}
