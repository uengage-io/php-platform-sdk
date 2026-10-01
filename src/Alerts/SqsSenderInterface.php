<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Alerts;

/**
 * Transport seam between AlertsClient and the alerts SQS FIFO queue.
 *
 * Exists so the validation / buffering / fail-open behaviour of
 * `raise()` can be tested without an AWS account or the AWS SDK
 * installed, and so a host application that already owns a configured
 * SqsClient can hand it in rather than have the SDK build a second one.
 * Mirrors {@see \Uengage\PlatformSdk\Events\SnsPublisherInterface}.
 */
interface SqsSenderInterface
{
    /**
     * Send up to 10 alert messages (SQS SendMessageBatch's limit) to a
     * FIFO queue, in order.
     *
     * Each message is a fully-built alert message (see
     * AlertsClient::buildMessage()) carrying its `id`. Implementations
     * send it with MessageGroupId = AlertsClient::messageGroupId($message)
     * and MessageDeduplicationId = $message['id'].
     *
     * Implementations MUST NOT throw for a partial failure - return the
     * ids that failed instead. Throwing is reserved for a call that got
     * nowhere at all (network, credentials, missing SDK); AlertsClient
     * catches that and fails open.
     *
     * @param string            $queueUrl
     * @param array<int, array> $messages
     *
     * @return array<int, string> Message ids that did NOT reach the queue.
     */
    public function sendBatch(string $queueUrl, array $messages): array;
}
