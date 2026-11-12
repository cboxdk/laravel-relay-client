<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeInterface;

/**
 * A mail to send. At least one of `$html` and `$text` is needed. `$headers` may only
 * hold what Relay lets a sender set: any `X-` header and the ones in
 * {@see self::ALLOWED_HEADERS}; Relay refuses the whole mail for any other.
 *
 * - `$trackClicks` and `$trackOpens` turn click and open tracking on or off for this mail
 *   alone; null leaves each to the sending domain.
 * - `$tags` (up to 10, letters, digits, `_` and `-`) and `$metadata` (up to 20 string keys
 *   and values) are your own labels: Relay returns them with the mail and in every event
 *   about it, so a webhook can find your own record again.
 * - `$sendAt` holds the mail back until then, at most 30 days ahead; a moment that has
 *   passed means now.
 */
readonly class OutgoingMail
{
    /** The headers besides `X-…` a sender may set, in lower case. */
    public const array ALLOWED_HEADERS = ['list-unsubscribe', 'list-unsubscribe-post', 'list-id', 'in-reply-to', 'references', 'precedence', 'auto-submitted'];

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  array<string, string>  $headers
     * @param  list<MailAttachment>  $attachments
     * @param  list<string>  $tags
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public MailAddress $from,
        public array $to,
        public string $subject,
        public ?string $html = null,
        public ?string $text = null,
        public array $cc = [],
        public array $bcc = [],
        public ?string $replyTo = null,
        public array $headers = [],
        public array $attachments = [],
        public ?bool $trackClicks = null,
        public ?bool $trackOpens = null,
        public array $tags = [],
        public array $metadata = [],
        public ?DateTimeInterface $sendAt = null,
    ) {}

    /** The same mail with click tracking on or off for it alone; null leaves it to the sending domain. */
    public function trackingClicks(?bool $clicks): self
    {
        return $this->copy(trackClicks: [$clicks]);
    }

    /** The same mail with open tracking on or off for it alone; null leaves it to the sending domain. */
    public function trackingOpens(?bool $opens): self
    {
        return $this->copy(trackOpens: [$opens]);
    }

    /**
     * The same mail with these tags instead of its own.
     *
     * @param  list<string>  $tags
     */
    public function tagged(array $tags): self
    {
        return $this->copy(tags: $tags);
    }

    /**
     * The same mail with this metadata instead of its own.
     *
     * @param  array<string, string>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return $this->copy(metadata: $metadata);
    }

    /** The same mail, held back until `$at`; null sends it at once. */
    public function sendingAt(?DateTimeInterface $at): self
    {
        return $this->copy(sendAt: [$at]);
    }

    /** Whether Relay lets a sender set the header `$name`. */
    public static function allowsHeader(string $name): bool
    {
        return in_array(strtolower($name), self::ALLOWED_HEADERS, true) || preg_match('/\Ax-[a-z0-9-]{1,60}\z/i', $name) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tracking = Payload::compact(['clicks' => $this->trackClicks, 'opens' => $this->trackOpens]);

        return Payload::compact([
            'from' => $this->from->toArray(),
            'to' => $this->to,
            'cc' => $this->cc === [] ? null : $this->cc,
            'bcc' => $this->bcc === [] ? null : $this->bcc,
            'reply_to' => $this->replyTo,
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'tracking' => $tracking === [] ? null : $tracking,
            'send_at' => $this->sendAt === null ? null : Payload::iso($this->sendAt),
            'tags' => $this->tags === [] ? null : $this->tags,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
            'headers' => $this->headers === [] ? null : $this->headers,
            'attachments' => $this->attachments === [] ? null : array_map(static fn (MailAttachment $attachment): array => $attachment->toArray(), $this->attachments),
        ]);
    }

    /**
     * A copy with some parts replaced. A nullable part is passed wrapped in a list, so
     * "set to null" can be told from "leave as it is".
     *
     * @param  array{0: bool|null}|null  $trackClicks
     * @param  array{0: bool|null}|null  $trackOpens
     * @param  list<string>|null  $tags
     * @param  array<string, string>|null  $metadata
     * @param  array{0: DateTimeInterface|null}|null  $sendAt
     */
    private function copy(?array $trackClicks = null, ?array $trackOpens = null, ?array $tags = null, ?array $metadata = null, ?array $sendAt = null): self
    {
        return new self(
            $this->from, $this->to, $this->subject, $this->html, $this->text, $this->cc, $this->bcc, $this->replyTo, $this->headers, $this->attachments,
            $trackClicks === null ? $this->trackClicks : $trackClicks[0],
            $trackOpens === null ? $this->trackOpens : $trackOpens[0],
            $tags ?? $this->tags,
            $metadata ?? $this->metadata,
            $sendAt === null ? $this->sendAt : $sendAt[0],
        );
    }
}
