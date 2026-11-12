<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * One of a project's two sending limits, in recipients: how many it may send to in the
 * window (`$limit`), how many it has (`$used`: the last hour, or the last 24 hours) and
 * who set the number (`$origin`: `default`, `auto` for Relay's daily look, `request` or
 * `operator`).
 */
readonly class SendingLimitWindow
{
    public function __construct(
        public int $limit,
        public int $used = 0,
        public string $origin = 'default',
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Payload::int($data, 'limit'), Payload::int($data, 'used'), Payload::string($data, 'origin'));
    }

    /** How many more recipients fit in the window; never under zero. */
    public function remaining(): int
    {
        return max($this->limit - $this->used, 0);
    }
}
