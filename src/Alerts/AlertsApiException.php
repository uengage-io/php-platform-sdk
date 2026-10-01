<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Alerts;

use Uengage\PlatformSdk\Exceptions\ApiException;

/**
 * Non-2xx response from the alerts HTTP API.
 *
 * The service answers errors as `{"error": "<code>", ...}`. getErrorCode()
 * reads that code so callers can branch without parsing the body, e.g.
 * the reopen refusal:
 *
 *     } catch (AlertsApiException $e) {
 *         if ($e->getErrorCode() === AlertsClient::ERROR_NEWER_ALERT_OPEN) {
 *             $newerId = $e->getNewerAlertId(); // the open/muted alert to act on instead
 *         }
 *     }
 */
class AlertsApiException extends ApiException
{
    public function __construct(int $status, string $body)
    {
        parent::__construct('alerts', $status, $body);
    }

    /**
     * The decoded JSON error body, or null when the body is not a JSON object.
     */
    public function getErrorBody(): ?array
    {
        $decoded = json_decode($this->getBody(), true);
        return is_array($decoded) ? $decoded : null;
    }

    /** The body's `error` code (`not_found`, `newer_alert_open`, ...), or null. */
    public function getErrorCode(): ?string
    {
        $body = $this->getErrorBody();
        return $body !== null && isset($body['error']) && is_string($body['error']) ? $body['error'] : null;
    }

    /**
     * For a 409 `newer_alert_open` from setStatus(): the id of the open or
     * muted alert that blocked the reopen (the body's `id`). Null for any
     * other error, or when the server did not include it.
     */
    public function getNewerAlertId(): ?string
    {
        if ($this->getErrorCode() !== AlertsClient::ERROR_NEWER_ALERT_OPEN) {
            return null;
        }
        $body = $this->getErrorBody();
        return isset($body['id']) && is_string($body['id']) && $body['id'] !== '' ? $body['id'] : null;
    }
}
