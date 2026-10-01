<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Alerts;

use RuntimeException;

/**
 * Transport failure while sending raised alerts to the alerts queue.
 *
 * Thrown by {@see SqsSenderInterface} implementations and caught by
 * AlertsClient, which fails open: callers of `raise()` never see it.
 * HTTP errors from list()/get()/setStatus() are {@see AlertsApiException}.
 */
class AlertsException extends RuntimeException
{
}
