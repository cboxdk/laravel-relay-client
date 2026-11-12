<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A received mail. A list gives summaries; one mail fetched by id adds `$text`, `$html`,
 * `$headers` and a link on every attachment. Until the mail is parsed (`$status` is
 * `received`) the content is empty.
 *
 * `$html` is whatever the sender wrote: hostile input. Never put it in a page without
 * sanitising it. A `$kind` of `auto_reply` is not an answer from a person.
 */
readonly class InboundMessage
{
    /**
     * @param  array<string, string>  $authentication  what Relay's edge found for spf, dkim, dmarc, arc
     * @param  list<InboundAttachment>  $attachments
     * @param  array<string, string>  $headers  lower-case names, the first value of each
     */
    public function __construct(
        public string $id,
        public string $scopeId,
        public string $status,
        public string $recipient,
        public string $envelopeFrom,
        public int $size,
        public DateTimeImmutable $receivedAt,
        public ?string $kind = null,
        public ?MailAddress $from = null,
        public ?string $subject = null,
        public ?string $messageId = null,
        public ?string $inReplyTo = null,
        public array $authentication = [],
        public array $attachments = [],
        public ?string $text = null,
        public ?string $html = null,
        public array $headers = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $from = Payload::nullableMap($data, 'from');

        return new self(
            id: Payload::string($data, 'id'),
            scopeId: Payload::string($data, 'scope_id'),
            status: Payload::string($data, 'status'),
            recipient: Payload::string($data, 'recipient'),
            envelopeFrom: Payload::nullableString($data, 'envelope_from') ?? '',
            size: Payload::int($data, 'size'),
            receivedAt: Payload::date($data, 'received_at'),
            kind: Payload::nullableString($data, 'kind'),
            from: $from === null ? null : MailAddress::fromArray($from),
            subject: Payload::nullableString($data, 'subject'),
            messageId: Payload::nullableString($data, 'message_id'),
            inReplyTo: Payload::nullableString($data, 'in_reply_to'),
            authentication: Payload::stringMap($data, 'authentication'),
            attachments: array_map(InboundAttachment::fromArray(...), Payload::maps($data, 'attachments')),
            text: Payload::nullableString($data, 'text'),
            html: Payload::nullableString($data, 'html'),
            headers: Payload::stringMap($data, 'headers'),
        );
    }

    /** Whether Relay has read the mail apart yet; before that there is no content. */
    public function parsed(): bool
    {
        return $this->status === 'parsed';
    }
}
