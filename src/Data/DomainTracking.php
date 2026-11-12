<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * A sending domain's click tracking. With `$clicks` on, the `http` and `https` links in
 * the HTML of mail from the domain go through Relay unless a mail says otherwise.
 * `$host` is the domain's own host for those links (a CNAME to Relay's tracking host),
 * null for Relay's shared host. Opens are not tracked.
 */
readonly class DomainTracking
{
    public function __construct(
        public bool $clicks = false,
        public ?string $host = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            clicks: Payload::bool($data, 'clicks'),
            host: Payload::nullableString($data, 'host'),
        );
    }
}
