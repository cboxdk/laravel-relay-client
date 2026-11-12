<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * What Relay answered to a publish: the event's id, how many endpoints it was queued
 * for, and whether the idempotency key had been seen before (then nothing new was
 * published and `$id` is the first event's).
 */
readonly class Published
{
    public function __construct(
        public string $id = '',
        public int $deliveries = 0,
        public bool $duplicate = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'id'),
            Payload::int($data, 'deliveries'),
            Payload::bool($data, 'duplicate'),
        );
    }
}
