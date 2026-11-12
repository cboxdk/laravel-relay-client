<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/** How a webhook endpoint has been answering. `$circuitClosesAt` is set while Relay holds back after repeated failures. */
readonly class EndpointHealth
{
    public function __construct(
        public int $consecutiveFailures = 0,
        public ?DateTimeImmutable $lastSuccessAt = null,
        public ?DateTimeImmutable $lastFailureAt = null,
        public ?int $lastResponseCode = null,
        public ?DateTimeImmutable $circuitClosesAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            consecutiveFailures: Payload::nullableInt($data, 'consecutive_failures') ?? 0,
            lastSuccessAt: Payload::nullableDate($data, 'last_success_at'),
            lastFailureAt: Payload::nullableDate($data, 'last_failure_at'),
            lastResponseCode: Payload::nullableInt($data, 'last_response_code'),
            circuitClosesAt: Payload::nullableDate($data, 'circuit_closes_at'),
        );
    }
}
