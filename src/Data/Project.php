<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * A project: one of a product's own workspaces as Relay knows it. `$externalRef` is the
 * product's id for it, `$id` Relay's (`prj_…`). `$settings` is the free-form JSON object
 * the product stored with it.
 *
 * `$created` is true only on the project returned by the `put` that created it (Relay
 * answered 201); it is false on every other read.
 */
readonly class Project
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $id,
        public string $externalRef,
        public ?string $name,
        public DateTimeImmutable $createdAt,
        public array $settings = [],
        public ?DateTimeImmutable $suspendedAt = null,
        public bool $created = false,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data, bool $created = false): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            externalRef: Payload::string($data, 'external_ref'),
            name: Payload::nullableString($data, 'name'),
            createdAt: Payload::date($data, 'created_at'),
            settings: Payload::nullableMap($data, 'settings') ?? [],
            suspendedAt: Payload::nullableDate($data, 'suspended_at'),
            created: $created,
        );
    }

    public function suspended(): bool
    {
        return $this->suspendedAt !== null;
    }

    /** Whether `$ref` names this project, by external ref or by id. */
    public function is(string $ref): bool
    {
        return $ref === $this->externalRef || $ref === $this->id;
    }

    /**
     * The project as Relay serializes it. `$created` is not part of it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'external_ref' => $this->externalRef,
            'name' => $this->name,
            'settings' => $this->settings,
            'suspended_at' => $this->suspendedAt?->format(DateTimeInterface::ATOM),
            'created_at' => $this->createdAt->format(DateTimeInterface::ATOM),
        ];
    }
}
