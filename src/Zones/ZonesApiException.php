<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Zones;

use Uengage\PlatformSdk\Exceptions\ApiException;

class ZonesApiException extends ApiException
{
    public function __construct(int $status, string $body)
    {
        parent::__construct('zones', $status, $body);
    }
}
