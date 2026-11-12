<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

use Cbox\Relay\Client\Data\DeliveryFilter;

/**
 * One call {@see FakeRelay} received. `$actingProject` is what would have gone out as
 * `Relay-Project`. `$ref` is the id, external ref or address named in the path; `$name`
 * and `$settings` are set for a project put, `$filter` for a deliveries read, `$limit`
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
        public ?string $actingProject = null,
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
