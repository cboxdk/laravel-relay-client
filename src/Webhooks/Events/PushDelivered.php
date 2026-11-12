<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `push.delivered`: a push service took a message. Whether the device showed it nobody is told.
 * `$id` is the message's (`Relay::pushMessage($event->id)`) and `$externalRef` the
 * reference the subscription was stored with.
 */
readonly class PushDelivered implements RelayEvent
{
    public function __construct(
        public string $id,
        public string $scopeId,
        public string $subscriptionId,
        public ?string $externalRef = null,
        public int $attempts = 0,
    ) {}

    public static function type(): string
    {
        return 'push.delivered';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            scopeId: Payload::string($data, 'scope_id'),
            subscriptionId: Payload::string($data, 'subscription_id'),
            externalRef: Payload::nullableString($data, 'external_ref'),
            attempts: Payload::int($data, 'attempts'),
        );
    }
}
