<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `push.expired`: a push service said the subscription is gone. Relay removed it: ask the browser to subscribe again.
 * `$id` is the message's (`Relay::pushMessage($event->id)`) and `$externalRef` the
 * reference the subscription was stored with.
 */
readonly class PushExpired implements RelayEvent
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $subscriptionId,
        public ?string $externalRef = null,
        public int $attempts = 0,
    ) {}

    public static function type(): string
    {
        return 'push.expired';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            projectId: Payload::string($data, 'project_id'),
            subscriptionId: Payload::string($data, 'subscription_id'),
            externalRef: Payload::nullableString($data, 'external_ref'),
            attempts: Payload::int($data, 'attempts'),
        );
    }
}
