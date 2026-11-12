<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

use Cbox\Relay\Client\Data\DeliveryFilter;

/**
 * One call {@see FakeRelay} received. `$actingScope` is what would have gone out as
 * `Relay-Scope`. `$ref` is the scope named in the path (put and find); `$name`,
 * `$settings` and `$idempotencyKey` are set for a put, `$filter` for a deliveries read,
 * `$limit` and `$cursor` for the two lists.
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
    ) {}
}
