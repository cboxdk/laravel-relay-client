<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A mail Relay stored and what became of it. `$status` is Relay's own string: `scheduled`
 * (waiting for `$sendAt`), `queued`, `sent` (handed to the mail server, which is not the
 * same as delivered to an inbox), `quarantined`, `blocked`, `failed` or `cancelled`.
 * `$reason` says why it was held, blocked or failed. `$tracksClicks` and `$tracksOpens`
 * are the answers the mail was accepted with, from the mail itself or else its sending
 * domain. `$tags` and `$metadata` are what it was sent with. `$heldBy` says who holds a
 * `quarantined` mail: `project` (a threshold of its own, which it may release) or `relay`
 * (Relay's guard against abuse: it can be discarded, and only Relay's operator releases
 * it). `$idempotencyKey` is the key the mail was accepted with, when there was one.
 */
readonly class Mail
{
    /**
     * @param  array<string, float>  $verdicts  what the filter scored
     * @param  list<MailRecipient>  $recipients
     * @param  list<string>  $tags
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public string $status,
        public string $from,
        public string $subject,
        public DateTimeImmutable $createdAt,
        public ?string $reason = null,
        public array $verdicts = [],
        public array $recipients = [],
        public ?DateTimeImmutable $sentAt = null,
        public bool $tracksClicks = false,
        public bool $tracksOpens = false,
        public array $tags = [],
        public array $metadata = [],
        public ?DateTimeImmutable $sendAt = null,
        public ?string $heldBy = null,
        public ?string $idempotencyKey = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tracking = Payload::nullableMap($data, 'tracking');

        return new self(
            id: Payload::string($data, 'id'),
            status: Payload::string($data, 'status'),
            from: Payload::string($data, 'from'),
            subject: Payload::string($data, 'subject'),
            createdAt: Payload::date($data, 'created_at'),
            reason: Payload::nullableString($data, 'reason'),
            verdicts: Payload::numberMap($data, 'verdicts'),
            recipients: array_map(MailRecipient::fromArray(...), Payload::maps($data, 'recipients')),
            sentAt: Payload::nullableDate($data, 'sent_at'),
            tracksClicks: $tracking !== null && Payload::bool($tracking, 'clicks'),
            tracksOpens: $tracking !== null && ($tracking['opens'] ?? false) === true,
            tags: isset($data['tags']) ? Payload::strings($data, 'tags') : [],
            metadata: isset($data['metadata']) && $data['metadata'] !== [] ? Payload::stringMap($data, 'metadata') : [],
            sendAt: Payload::nullableDate($data, 'send_at'),
            heldBy: Payload::nullableString($data, 'held_by'),
            idempotencyKey: Payload::nullableString($data, 'idempotency_key'),
        );
    }

    /** Whether Relay took the mail for sending: `queued`, already `sent`, or `scheduled` for later. */
    public function accepted(): bool
    {
        return in_array($this->status, ['queued', 'sent', 'scheduled'], true);
    }

    /** Whether Relay's own guard holds it: releasing it is refused (`mail_held_by_relay`), discarding is not. */
    public function heldByRelay(): bool
    {
        return $this->status === 'quarantined' && $this->heldBy === 'relay';
    }

    /** Whether it waits for `$sendAt` and can still be cancelled. */
    public function scheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    /**
     * The same mail as it is after something happened to it. Used by the fake.
     *
     * @param  list<MailRecipient>|null  $recipients
     */
    public function became(string $status, ?string $reason = null, ?array $recipients = null, ?string $heldBy = null): self
    {
        return new self($this->id, $status, $this->from, $this->subject, $this->createdAt, $reason, $this->verdicts, $recipients ?? $this->recipients, $this->sentAt, $this->tracksClicks, $this->tracksOpens, $this->tags, $this->metadata, $this->sendAt, $status === 'quarantined' ? ($heldBy ?? $this->heldBy ?? 'project') : null, $this->idempotencyKey);
    }

    /** The same mail with the key it was accepted with. Used by the fake. */
    public function withIdempotencyKey(?string $key): self
    {
        return new self($this->id, $this->status, $this->from, $this->subject, $this->createdAt, $this->reason, $this->verdicts, $this->recipients, $this->sentAt, $this->tracksClicks, $this->tracksOpens, $this->tags, $this->metadata, $this->sendAt, $this->heldBy, $key);
    }
}
