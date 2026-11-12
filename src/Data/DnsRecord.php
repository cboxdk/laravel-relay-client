<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * A DNS record Relay asks you to publish. `$purpose` (`dkim`, `spf`, `dmarc`, `tracking`) and
 * `$required` are set for a sending domain's records; an inbound route's one
 * verification record has no purpose and is always required. `$status` is what Relay
 * found when it last looked for a sending domain's record: `found`, `missing`, `different`
 * (something else is published there) or `unchecked`; null where Relay does not say.
 */
readonly class DnsRecord
{
    public function __construct(
        public string $type,
        public string $name,
        public string $value,
        public ?string $purpose = null,
        public bool $required = true,
        public ?string $status = null,
    ) {}

    /** Whether Relay found this record as it asked for it. */
    public function found(): bool
    {
        return $this->status === 'found';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: Payload::string($data, 'type'),
            name: Payload::string($data, 'name'),
            value: Payload::string($data, 'value'),
            purpose: Payload::nullableString($data, 'purpose'),
            required: array_key_exists('required', $data) ? Payload::bool($data, 'required') : true,
            status: Payload::nullableString($data, 'status'),
        );
    }
}
