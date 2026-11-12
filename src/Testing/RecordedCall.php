<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

use Cbox\Relay\Client\Data\DeliveryFilter;

/**
 * One call {@see FakeRelay} received. `$actingScope` is what would have gone out as
 * `Relay-Scope`. `$ref` is the id, external ref or address named in the path; `$name`
 * and `$settings` are set for a scope put, `$filter` for a deliveries read, `$limit`
 * and `$cursor` for the paged lists, `$idempotencyKey` where the call carried one.
 *
 * `$subject` is the value object the call was made with, when it had one: the
 * `NewEvent` of a publish, the `OutgoingMail` of a send, the `JudgeInput`, the
 * `NewEndpoint`, the `EndpointChanges`, the `BrowserSubscription` or the `SentPush`.
 */
readonly class RecordedCall
{
    /**
     * @param  array<string, mixed>|null  $settings
     */
    public function __construct(
        public Operation $operation,
        public ?string $actingScope = null,
        public ?string $ref = null,
        public ?string $name = null,
        public ?array $settings = null,
        public ?string $idempotencyKey = null,
        public ?DeliveryFilter $filter = null,
        public ?int $limit = null,
        public ?string $cursor = null,
        public ?object $subject = null,
    ) {}
}
