<?php

declare(strict_types=1);

namespace Uengage\PlatformSdk\Tests\Support;

use Throwable;
use Uengage\PlatformSdk\Alerts\SqsSenderInterface;

/**
 * Records what AlertsClient handed to SQS, and can be told to fail -
 * either by throwing (transport dead) or by reporting per-entry failures
 * (SQS accepted the call, rejected some entries).
 */
class StubSqsSender implements SqsSenderInterface
{
    /** @var array<int, array{queueUrl: string, messages: array}> */
    public $calls = [];

    /** @var Throwable|null Thrown from sendBatch when set. */
    public $throwable = null;

    /** @var array<int, string> Ids reported as failed, when present in the batch. */
    public $failIds = [];

    public function sendBatch(string $queueUrl, array $messages): array
    {
        $this->calls[] = ['queueUrl' => $queueUrl, 'messages' => $messages];
        if ($this->throwable !== null) {
            throw $this->throwable;
        }
        $failed = [];
        foreach ($messages as $message) {
            if (in_array($message['id'], $this->failIds, true)) {
                $failed[] = $message['id'];
            }
        }
        return $failed;
    }

    /** Every message handed over, across all calls. */
    public function allMessages(): array
    {
        $out = [];
        foreach ($this->calls as $call) {
            foreach ($call['messages'] as $message) {
                $out[] = $message;
            }
        }
        return $out;
    }
}
