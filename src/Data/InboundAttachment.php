<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * An attachment of a received mail. `$url` is only set when one mail is fetched, and
 * only works for a short while (15 minutes today): download it, do not store the link.
 */
readonly class InboundAttachment
{
    public function __construct(
        public string $id,
        public string $name,
        public string $type,
        public int $size,
        public bool $inline = false,
        public ?string $contentId = null,
        public ?string $url = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            name: Payload::string($data, 'name'),
            type: Payload::string($data, 'type'),
            size: Payload::int($data, 'size'),
            inline: Payload::bool($data, 'inline'),
            contentId: Payload::nullableString($data, 'content_id'),
            url: Payload::nullableString($data, 'url'),
        );
    }
}
