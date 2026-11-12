<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * A file to send with a mail. `$content` is the raw bytes; it goes out base64-encoded.
 * With a `$contentId` (letters, digits, `.`, `_` and `-`) the file is drawn inside the
 * HTML where it says `cid:` and that id (`<img src="cid:logo">`) instead of being listed
 * as an attachment.
 */
readonly class MailAttachment
{
    public function __construct(
        public string $name,
        public string $type,
        public string $content,
        public ?string $contentId = null,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $attachment = ['name' => $this->name, 'type' => $this->type, 'content' => base64_encode($this->content)];

        return $this->contentId === null ? $attachment : [...$attachment, 'content_id' => $this->contentId];
    }
}
