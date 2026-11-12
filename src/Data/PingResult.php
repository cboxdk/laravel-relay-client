<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** How a webhook endpoint answered a test event sent just now. `$outcome` is Relay's own string. */
readonly class PingResult
{
    public function __construct(
        public string $outcome = '',
        public ?int $responseCode = null,
        public int $durationMs = 0,
        public ?string $error = null,
    ) {}

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
