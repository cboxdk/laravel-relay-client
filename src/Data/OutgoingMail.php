<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * A mail to send. At least one of `$html` and `$text` is needed. `$headers` may only
 * hold what Relay lets a sender set: any `X-` header and the ones in
 * {@see self::ALLOWED_HEADERS}; Relay refuses the whole mail for any other. `$trackClicks`
 * turns click tracking on or off for this mail alone; null leaves it to the sending domain.
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
    ) {}

    /** The same mail with click tracking on or off for it alone; null leaves it to the sending domain. */
    public function trackingClicks(?bool $clicks): self
    {
        return new self($this->from, $this->to, $this->subject, $this->html, $this->text, $this->cc, $this->bcc, $this->replyTo, $this->headers, $this->attachments, $clicks);
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
        return Payload::compact([
            'from' => $this->from->toArray(),
            'to' => $this->to,
            'cc' => $this->cc === [] ? null : $this->cc,
            'bcc' => $this->bcc === [] ? null : $this->bcc,
            'reply_to' => $this->replyTo,
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'tracking' => $this->trackClicks === null ? null : ['clicks' => $this->trackClicks],
            'headers' => $this->headers === [] ? null : $this->headers,
            'attachments' => $this->attachments === [] ? null : array_map(static fn (MailAttachment $attachment): array => $attachment->toArray(), $this->attachments),
        ]);
    }
}
