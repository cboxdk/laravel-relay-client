<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A pattern of addresses a scope receives mail for. `$pattern` is the claim as a person
 *
 * writes it (`*@acme.dk`, `r-*@cbox.email`). A route that is `pending` receives nothing
 * yet; `$verification` is the DNS record that proves a domain is yours, null when none
 * is needed.
 */
readonly class InboundRoute
{
    public function __construct(
        public string $id,
        public string $type,
        public string $domain,
        public string $pattern,
        public string $status,
        public DateTimeImmutable $createdAt,
        public ?string $prefix = null,
        public ?DnsRecord $verification = null,
        public ?DateTimeImmutable $verifiedAt = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $verification = Payload::nullableMap($data, 'verification');

        return new self(
            id: Payload::string($data, 'id'),
            type: Payload::string($data, 'type'),
            domain: Payload::string($data, 'domain'),
            pattern: Payload::string($data, 'pattern'),
            status: Payload::string($data, 'status'),
            createdAt: Payload::date($data, 'created_at'),
            prefix: Payload::nullableString($data, 'prefix'),
            verification: $verification === null ? null : DnsRecord::fromArray($verification),
            verifiedAt: Payload::nullableDate($data, 'verified_at'),
        );
    }

    public function active(): bool
    {
        return $this->status === 'active';
    }
}
