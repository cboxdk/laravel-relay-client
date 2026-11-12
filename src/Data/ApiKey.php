<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * What Relay tells about the key a call was made with: its id (`key_…`), name, the
 * visible prefix and its abilities. Never the secret itself.
 */
readonly class ApiKey
{
    /**
     * @param  list<string>  $abilities
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $prefix,
        public array $abilities = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'id'),
            Payload::string($data, 'name'),
            Payload::string($data, 'prefix'),
            Payload::strings($data, 'abilities'),
        );
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    /**
     * @return array{id: string, name: string, prefix: string, abilities: list<string>}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'prefix' => $this->prefix, 'abilities' => $this->abilities];
    }
}
