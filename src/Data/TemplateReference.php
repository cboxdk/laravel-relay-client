<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** The template a judged content belongs to: your own id for it and, optionally, its version. */
readonly class TemplateReference
{
    public function __construct(
        public string $id,
        public ?string $version = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Payload::string($data, 'id'), Payload::nullableString($data, 'version'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Payload::compact(['id' => $this->id, 'version' => $this->version]);
    }
}
