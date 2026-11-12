<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

use Cbox\Relay\Client\Data\PushUrgency;

/** One push message {@see FakeRelay} was asked to send: to which subscription, with what, for which project. */
readonly class SentPush
{
    /**
     * @param  string|array<string, mixed>  $payload
     */
    public function __construct(
        public string $subscription,
        public string|array $payload,
        public ?int $ttl = null,
        public ?PushUrgency $urgency = null,
        public ?string $topic = null,
        public ?string $project = null,
    ) {}
}
