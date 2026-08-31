<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Support;

use Throwable;
use Uengage\PlatformSdk\Events\SnsPublisherInterface;

/**
 * Records what EventsClient handed to SNS, and can be told to fail —
 * either by throwing (transport dead) or by reporting per-entry failures
 * (SNS accepted the call, rejected some entries).
 */
class StubSnsPublisher implements SnsPublisherInterface
{
    /** @var array<int, array{topicArn: string, envelopes: array}> */
    public $calls = [];

    /** @var Throwable|null Thrown from publishBatch when set. */
    public $throwable = null;

    /** @var bool When true, every entry is reported as failed. */
    public $failAll = false;

    public function publishBatch(string $topicArn, array $envelopes): array
    {
        $this->calls[] = ['topicArn' => $topicArn, 'envelopes' => $envelopes];
        if ($this->throwable !== null) {
            throw $this->throwable;
        }
        if ($this->failAll) {
            return array_map(function ($envelope) {
                return $envelope['id'];
            }, $envelopes);
        }
        return [];
    }

    /** Every envelope handed over, across all calls. */
    public function allEnvelopes(): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            foreach ($call['envelopes'] as $envelope) {
                $out[] = $envelope;
            }
        }
        return $out;
    }
}
