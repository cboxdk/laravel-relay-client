<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** A failed webhook delivery that was queued again: its id and the status it has now. */
readonly class DeliveryRetry
{
    public function __construct(
        public string $id = '',
        public string $status = 'pending',
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Payload::string($data, 'id'), Payload::string($data, 'status'));
    }
}
