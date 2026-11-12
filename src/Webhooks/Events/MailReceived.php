<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;
use DateTimeImmutable;

/**
 * `mail.received`: a mail arrived for one of the scope's inbound routes and was parsed.
 * No content travels with the event: fetch it with `Relay::inbound()->find($event->id)`.
 * An `$autoReply` is not an answer from a person.
 */
readonly class MailReceived implements RelayEvent
{
    public function __construct(
        public string $id,
        public string $scopeId,
        public string $recipient,
        public string $envelopeFrom = '',
        public ?string $from = null,
        public ?string $subject = null,
        public string $kind = 'mail',
        public bool $autoReply = false,
        public int $attachments = 0,
        public int $size = 0,
        public ?DateTimeImmutable $receivedAt = null,
    ) {}

    public static function type(): string
    {
        return 'mail.received';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            scopeId: Payload::string($data, 'scope_id'),
            recipient: Payload::string($data, 'recipient'),
            envelopeFrom: Payload::nullableString($data, 'envelope_from') ?? '',
            from: Payload::nullableString($data, 'from'),
            subject: Payload::nullableString($data, 'subject'),
            kind: Payload::string($data, 'kind'),
            autoReply: Payload::bool($data, 'auto_reply'),
            attachments: Payload::int($data, 'attachments'),
            size: Payload::int($data, 'size'),
            receivedAt: Payload::nullableDate($data, 'received_at'),
        );
    }
}
