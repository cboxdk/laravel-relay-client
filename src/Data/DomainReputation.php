<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * How the mail a scope sent from one domain fared in the last `$windowDays` days,
 * counted in recipients: `$sent` the mail server took, of those `$bounced` refused for
 * good and `$complained` reported as spam. A rate is null when nothing was sent.
 */
readonly class DomainReputation
{
    public function __construct(
        public string $id,
        public string $domain,
        public int $windowDays = 7,
        public int $sent = 0,
        public int $bounced = 0,
        public int $complained = 0,
        public ?float $bounceRate = null,
        public ?float $complaintRate = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            domain: Payload::string($data, 'domain'),
            windowDays: Payload::int($data, 'window_days'),
            sent: Payload::int($data, 'sent'),
            bounced: Payload::int($data, 'bounced'),
            complained: Payload::int($data, 'complained'),
            bounceRate: Payload::nullableNumber($data, 'bounce_rate'),
            complaintRate: Payload::nullableNumber($data, 'complaint_rate'),
        );
    }
}
