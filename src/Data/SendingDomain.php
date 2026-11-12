<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;

/**
 * A domain a project sends mail from, with the DNS records to publish. It can send once
 * `$status` is `verified`; a verified domain whose DKIM record has gone becomes `failed`.
 * `$tracking` is its click tracking; a host of its own for tracked links adds a record
 * with the purpose `tracking` to `$records`.
 */
readonly class SendingDomain
{
    /**
     * @param  list<DnsRecord>  $records
     */
    public function __construct(
        public string $id,
        public string $domain,
        public string $status,
        public DateTimeImmutable $createdAt,
        public array $records = [],
        public ?DateTimeImmutable $verifiedAt = null,
        public ?DateTimeImmutable $checkedAt = null,
        public DomainTracking $tracking = new DomainTracking,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $tracking = Payload::nullableMap($data, 'tracking');

        return new self(
            id: Payload::string($data, 'id'),
            domain: Payload::string($data, 'domain'),
            status: Payload::string($data, 'status'),
            createdAt: Payload::date($data, 'created_at'),
            records: array_map(DnsRecord::fromArray(...), Payload::maps($data, 'records')),
            verifiedAt: Payload::nullableDate($data, 'verified_at'),
            checkedAt: Payload::nullableDate($data, 'checked_at'),
            tracking: $tracking === null ? new DomainTracking : DomainTracking::fromArray($tracking),
        );
    }

    public function verified(): bool
    {
        return $this->status === 'verified';
    }
}
