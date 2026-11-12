<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * One reason behind a judgment's score: which verdict it raised, the layer (1 protocol
 * and headers, 2 rules, 3 a model), the rule and what it saw.
 */
readonly class Finding
{
    public function __construct(
        public string $verdict,
        public int $layer,
        public string $rule,
        public float $score,
        public string $detail,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'verdict'),
            Payload::int($data, 'layer'),
            Payload::string($data, 'rule'),
            Payload::number($data, 'score'),
            Payload::string($data, 'detail'),
        );
    }
}
