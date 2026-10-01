<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Auth;

use Uengage\PlatformSdk\Exceptions\ApiException;

class AuthApiException extends ApiException
{
    public function __construct(int $status, string $body)
    {
        parent::__construct('auth', $status, $body);
    }
}
