<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * The scope a call acted for, as `whoami` names it: the id, the product's own
 * `external_ref` and the name, without the settings a full {@see Scope} carries.
 */
readonly class ScopeReference
{
    public function __construct(
        public string $id,
        public string $externalRef,
        public string $name,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'id'),
            Payload::string($data, 'external_ref'),
            Payload::string($data, 'name'),
        );
    }

    /**
     * @return array{id: string, external_ref: string, name: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'external_ref' => $this->externalRef, 'name' => $this->name];
    }
}
