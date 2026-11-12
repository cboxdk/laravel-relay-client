<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * What an erasure removed: `$removed` counts the rows that went (events published with
 * the subject, their deliveries and their attempts). Zero when there was nothing left.
 */
readonly class Erasure
{
    public function __construct(
        public string $subject = '',
        public int $removed = 0,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Payload::string($data, 'subject'), Payload::int($data, 'removed'));
    }
}
