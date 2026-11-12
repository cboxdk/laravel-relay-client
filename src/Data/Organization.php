<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** The organization a key belongs to (`org_…`). */
readonly class Organization
{
    public function __construct(
        public string $id,
        public string $name,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Payload::string($data, 'id'), Payload::string($data, 'name'));
    }

    /**
     * @return array{id: string, name: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
