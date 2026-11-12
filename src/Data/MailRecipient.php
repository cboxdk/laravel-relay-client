<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * One recipient of a sent mail and what became of it. `$type` is `to`, `cc` or `bcc`;
 * `$status` is Relay's own string (`queued`, `sent`, `suppressed`, `bounced`,
 * `complained`, `failed` today).
 */
readonly class MailRecipient
{
    public function __construct(
        public string $address,
        public string $type,
        public string $status,
        public ?string $detail = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'address'),
            Payload::string($data, 'type'),
            Payload::string($data, 'status'),
            Payload::nullableString($data, 'detail'),
        );
    }
}
