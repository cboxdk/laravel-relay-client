<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * How a webhook endpoint answered a test event sent just now. `$outcome` is Relay's own
 * string, kept as it came so an outcome Relay adds later does not break a read;
 * `outcome()` gives it as an {@see AttemptOutcome} when it is one this client knows.
 */
readonly class PingResult
{
    public function __construct(
        public string $outcome = '',
        public ?int $responseCode = null,
        public int $durationMs = 0,
        public ?string $error = null,
    ) {}

    /** The outcome as an enum; null for one this client does not know. */
    public function outcome(): ?AttemptOutcome
    {
        return AttemptOutcome::tryFrom($this->outcome);
    }

    /** Whether the receiver took the test event. */
    public function succeeded(): bool
    {
        return $this->outcome === AttemptOutcome::Success->value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'outcome'),
            Payload::nullableInt($data, 'response_code'),
            Payload::int($data, 'duration_ms'),
            Payload::nullableString($data, 'error'),
        );
    }
}
