<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * An address a project never sends to. `$source` is the mail that caused it, when one did.
 * `$created` is true only on the answer that added it (Relay answered 201); an address
 * that was listed already keeps its first reason.
 */
readonly class Suppression
{
    public function __construct(
        public string $address,
        public string $reason,
        public DateTimeImmutable $createdAt,
        public ?string $source = null,
        public bool $created = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, bool $created = false): self
    {
        return new self(
            address: Payload::string($data, 'address'),
            reason: Payload::string($data, 'reason'),
            createdAt: Payload::date($data, 'created_at'),
            source: Payload::nullableString($data, 'source'),
            created: $created,
        );
    }
}
