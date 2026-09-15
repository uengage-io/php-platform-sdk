<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Exceptions;

use RuntimeException;

/**
 * Thrown when an authentication step fails (token mint rejected by the
 * auth surface, legacy-session exchange returned 4xx, etc).
 */
class AuthenticationException extends RuntimeException
{
}
