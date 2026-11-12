<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Store;

use Cbox\Relay\Client\Contracts\MessageStore;
use Cbox\Relay\Client\Data\Mail;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Models\RelayMessage;
use Cbox\Relay\Client\Models\RelayMessageEvent;
use Cbox\Relay\Client\RelayConfig;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The store in this app's own database (`relay_messages`, `relay_message_events`).
 *
 * Idempotent in both directions: a mail id is one row however often it is recorded, and
 * a webhook delivery is one event row, so the counters on the mail are raised once per
 * delivery however often Relay retries it.
 */
class DatabaseMessageStore implements MessageStore
{
    /** The events that are about one outbound mail, and the status each leaves on it. */
    public const array STATUS = [
        'mail.accepted' => null,
        'mail.blocked' => 'blocked',
        'mail.delivered' => 'delivered',
        'mail.bounced' => 'bounced',
        'mail.complained' => 'complained',
        'mail.clicked' => null,
    ];

    /** A later event never replaces a status that says more: a complaint outlives a delivery. */
    private const array RANK = ['queued' => 0, 'quarantined' => 0, 'blocked' => 1, 'delivered' => 2, 'bounced' => 3, 'complained' => 4];

    public function __construct(private readonly RelayConfig $config) {}

    public function enabled(): bool
    {
        return true;
    }

    public function recordSent(Mail $mail, OutgoingMail $outgoing, ?string $project = null, ?string $connection = null): void
    {
        RelayMessage::query()->firstOrCreate(['id' => $mail->id], [
            'connection' => $connection,
            'project' => $project,
            'subject' => match ($this->config->storeSubject()) {
                'plain' => mb_substr($outgoing->subject, 0, 998),
                'none' => null,
                default => hash('sha256', $outgoing->subject),
            },
            'from_address' => $outgoing->from->address,
            'recipients' => count($outgoing->to) + count($outgoing->cc) + count($outgoing->bcc),
            'status' => $mail->status,
        ]);
    }

    public function describe(string $mailId, ?string $mailable): void
    {
        if ($mailable !== null && $mailable !== '') {
            RelayMessage::query()->whereKey($mailId)->whereNull('mailable')->update(['mailable' => mb_substr($mailable, 0, 191)]);
        }
    }

    public function recordEvent(IncomingWebhook $webhook, ?string $connection = null): bool
    {
        if (! array_key_exists($webhook->type, self::STATUS)) {
            return true;
        }

        $mailId = $webhook->data['id'] ?? null;

        if (! is_string($mailId) || $mailId === '') {
            return true;
        }

        $recipient = $webhook->data['recipient'] ?? null;

        try {
            RelayMessageEvent::query()->create([
                'delivery_id' => $webhook->deliveryId ?? $webhook->id,
                'event_id' => $webhook->id,
                'message_id' => $mailId,
                'type' => $webhook->type,
                'recipient' => is_string($recipient) ? $recipient : null,
                'data' => $webhook->data,
                'occurred_at' => $webhook->occurredAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            // This delivery was kept before: nothing to count again.
            return false;
        }

        $message = RelayMessage::query()->find($mailId);

        if ($message instanceof RelayMessage) {
            $counter = match ($webhook->type) {
                'mail.delivered' => 'delivered',
                'mail.bounced' => 'bounced',
                'mail.complained' => 'complained',
                'mail.clicked' => 'clicked',
                default => null,
            };
            $status = self::STATUS[$webhook->type];
            $changes = ['last_event_at' => $webhook->occurredAt ?? $message->freshTimestamp()];

            if ($status !== null && self::RANK[$status] >= (self::RANK[$message->status] ?? 0)) {
                $changes['status'] = $status;
            }

            if ($counter !== null) {
                $message->increment($counter, 1, $changes);
            } else {
                $message->forceFill($changes)->save();
            }
        }

        return true;
    }
}
