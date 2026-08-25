<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Events;

/**
 * Transport seam between EventsClient and SNS.
 *
 * Exists so the buffering / envelope / fail-open behaviour can be tested
 * without an AWS account or the AWS SDK installed, and so a host
 * application that already owns a configured SnsClient can hand it in
 * rather than have the SDK build a second one.
 */
interface SnsPublisherInterface
{
    /**
     * Publish up to 10 envelopes in one call (SNS PublishBatch's limit).
     *
     * Implementations MUST NOT throw for a partial failure - return the
     * ids that failed instead. Throwing is reserved for a call that got
     * nowhere at all (network, credentials, missing SDK); EventsClient
     * catches that and fails open.
     *
     * @param string            $topicArn
     * @param array<int, array> $envelopes Fully-built event envelopes.
     *
     * @return array<int, string> Envelope ids that did NOT make it onto the bus.
     */
    public function publishBatch(string $topicArn, array $envelopes): array;
}
