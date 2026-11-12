<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * One event type a project offers its webhook receivers. Only `$type` is required; the
 * rest describes it for the people who subscribe.
 */
readonly class EventType
{
    /**
     * @param  array<string, mixed>|null  $example
     */
    public function __construct(
        public string $type,
        public ?string $category = null,
        public ?string $name = null,
        public ?string $description = null,
        public ?array $example = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: Payload::string($data, 'type'),
            category: Payload::nullableString($data, 'category'),
            name: Payload::nullableString($data, 'name'),
            description: Payload::nullableString($data, 'description'),
            example: Payload::nullableMap($data, 'example'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Payload::compact([
            'type' => $this->type,
            'category' => $this->category,
            'name' => $this->name,
            'description' => $this->description,
            'example' => $this->example,
        ]);
    }
}
