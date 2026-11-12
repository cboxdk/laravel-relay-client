<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A mail Relay stored and what became of it. `$status` is Relay's own string: `queued`,
 * `sent` (handed to the mail server, which is not the same as delivered to an inbox),
 * `quarantined`, `blocked` or `failed`. `$reason` says why it was held, blocked or failed.
 */
readonly class Mail
{
    /**
     * @param  array<string, float>  $verdicts  what the filter scored
     * @param  list<MailRecipient>  $recipients
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
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
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
        );
    }

    /** Whether Relay took the mail for sending (`queued` or already `sent`). */
    public function accepted(): bool
    {
        return in_array($this->status, ['queued', 'sent'], true);
    }
}
