<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeInterface;

/**
 * Which of a project's mail to list. Every part is optional and each one narrows.
 * `$from` and `$to` are whole addresses, compared without regard to case; `$to` matches a
 * mail that has the address among its recipients, bcc included. `$createdAfter` includes
 * its own moment, `$createdBefore` does not.
 */
readonly class MailFilter
{
    public function __construct(
        public ?string $status = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?string $tag = null,
        public ?DateTimeInterface $createdAfter = null,
        public ?DateTimeInterface $createdBefore = null,
    ) {}

    /** Whether `$mail` passes this filter. Used by the fake. */
    public function matches(Mail $mail): bool
    {
        $to = $this->to === null ? null : strtolower($this->to);

        return ($this->status === null || $mail->status === $this->status)
            && ($this->from === null || strtolower($mail->from) === strtolower($this->from))
            && ($to === null || array_any($mail->recipients, static fn (MailRecipient $recipient): bool => strtolower($recipient->address) === $to))
            && ($this->tag === null || in_array($this->tag, $mail->tags, true))
            && ($this->createdAfter === null || $mail->createdAt >= $this->createdAfter)
            && ($this->createdBefore === null || $mail->createdAt < $this->createdBefore);
    }

    /**
     * The query string parameters, without the ones that are not set.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'status' => $this->status,
            'from' => $this->from,
            'to' => $this->to,
            'tag' => $this->tag,
            'created_after' => $this->createdAfter === null ? null : Payload::iso($this->createdAfter),
            'created_before' => $this->createdBefore === null ? null : Payload::iso($this->createdBefore),
        ], static fn (?string $value): bool => $value !== null);
    }
}
