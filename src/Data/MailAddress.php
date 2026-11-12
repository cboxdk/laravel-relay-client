<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** A mail address with the name shown beside it, when there is one. */
readonly class MailAddress
{
    public function __construct(
        public string $address,
        public ?string $name = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Payload::string($data, 'address'), Payload::nullableString($data, 'name'));
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->name === null || $this->name === ''
            ? ['address' => $this->address]
            : ['address' => $this->address, 'name' => $this->name];
    }
}
