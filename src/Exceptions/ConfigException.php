<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Exceptions;

use RuntimeException;

/**
 * Thrown when the SDK is constructed with an invalid combination of
 * config options (e.g. two mutually-exclusive auth modes, missing
 * required fields).
 */
class ConfigException extends RuntimeException
{
}
