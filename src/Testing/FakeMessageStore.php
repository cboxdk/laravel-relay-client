<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

use Cbox\Relay\Client\Contracts\MessageStore;
use Cbox\Relay\Client\Data\Mail;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;
use PHPUnit\Framework\Assert;

/**
 * The local store in memory, for a test that wants to know what would have been kept
 * without a database: bind it as `Contracts\MessageStore`.
 */
class FakeMessageStore implements MessageStore
{
    /** @var array<string, array{mail: Mail, outgoing: OutgoingMail, project: string|null, connection: string|null, mailable: string|null}> by mail id */
    public array $sent = [];

    /** @var array<string, IncomingWebhook> by delivery id */
    public array $events = [];

    public function enabled(): bool
    {
        return true;
    }

    public function recordSent(Mail $mail, OutgoingMail $outgoing, ?string $project = null, ?string $connection = null): void
    {
        $this->sent[$mail->id] ??= ['mail' => $mail, 'outgoing' => $outgoing, 'project' => $project, 'connection' => $connection, 'mailable' => null];
    }

    public function describe(string $mailId, ?string $mailable): void
    {
        if (isset($this->sent[$mailId]) && $this->sent[$mailId]['mailable'] === null) {
            $this->sent[$mailId]['mailable'] = $mailable;
        }
    }

    public function recordEvent(IncomingWebhook $webhook, ?string $connection = null): bool
    {
        $key = $webhook->deliveryId ?? $webhook->id;

        if (isset($this->events[$key])) {
            return false;
        }

        $this->events[$key] = $webhook;

        return true;
    }

    public function assertSent(string $mailId): void
    {
        Assert::assertArrayHasKey($mailId, $this->sent, "The mail {$mailId} was not kept.");
    }

    public function assertEventKept(string $type, int $times = 1): void
    {
        Assert::assertCount($times, array_filter($this->events, static fn (IncomingWebhook $webhook): bool => $webhook->type === $type), "Expected {$times} kept event(s) of type {$type}.");
    }
}
