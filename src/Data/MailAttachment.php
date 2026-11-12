<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** A file to send with a mail. `$content` is the raw bytes; it goes out base64-encoded. */
readonly class MailAttachment
{
    public function __construct(
        public string $name,
        public string $type,
        public string $content,
    ) {}

    /**
     * @return array{name: string, type: string, content: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'type' => $this->type, 'content' => base64_encode($this->content)];
    }
}
