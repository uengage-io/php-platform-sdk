<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Events;

use RuntimeException;

/**
 * Raised by the SNS transport when a publish attempt gets nowhere -
 * missing AWS SDK, missing credentials, network failure.
 *
 * EventsClient catches this during its flush and logs via `error_log()`
 * rather than letting it escape: the bus must never be able to fail an
 * order status change. Callers that want to observe publish failures
 * should call `flush()` explicitly and inspect `failedCount()`, or read
 * the error log.
 */
class EventsException extends RuntimeException
{
}
