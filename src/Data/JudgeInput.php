<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * What the judge is asked to score. At least one of `$html` and `$text` is needed;
 * Relay refuses content with neither.
 */
readonly class JudgeInput
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public ?string $html = null,
        public ?string $text = null,
        public ?string $subject = null,
        public ?MailAddress $from = null,
        public ?TemplateReference $template = null,
        public ?JudgeKind $kind = null,
        public ?Direction $direction = null,
        public array $headers = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Payload::compact([
            'kind' => $this->kind?->value,
            'direction' => $this->direction?->value,
            'from' => $this->from?->toArray(),
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'headers' => $this->headers === [] ? null : $this->headers,
            'template' => $this->template?->toArray(),
        ]);
    }
}
