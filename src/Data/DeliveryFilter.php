<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Which lines of the delivery log to read. Every part is optional; `new DeliveryFilter`
 * reads everything the key may see. `$from` and `$to` bound `occurred_at`.
 */
readonly class DeliveryFilter
{
    public function __construct(
        public ?string $channel = null,
        public ?string $status = null,
        public ?DateTimeInterface $from = null,
        public ?DateTimeInterface $to = null,
    ) {}

    /** Whether `$delivery` passes this filter, both bounds included. Used by the fake. */
    public function matches(Delivery $delivery): bool
    {
        return ($this->channel === null || $delivery->channel === $this->channel)
            && ($this->status === null || $delivery->status === $this->status)
            && ($this->from === null || $delivery->occurredAt >= $this->from)
            && ($this->to === null || $delivery->occurredAt <= $this->to);
    }

    /**
     * The query string parameters, without the ones that are not set. Dates go out as
     * ISO 8601 in UTC.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'channel' => $this->channel,
            'status' => $this->status,
            'from' => self::iso($this->from),
            'to' => self::iso($this->to),
        ], static fn (?string $value): bool => $value !== null);
    }

    private static function iso(?DateTimeInterface $date): ?string
    {
        return $date === null
            ? null
            : DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
