<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\EventType;

/** The event types a scope offers its webhook receivers. Reached through `Relay::eventTypes()`. */
class EventTypes
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingScope $scope = new ActingScope,
    ) {}

    /**
     * Make the scope's types exactly these. Safe to repeat on every deploy; a type left
     * out is removed, and a type that was not declared can be neither published nor
     * subscribed to. A plain string declares a type by name alone.
     *
     * @param  list<EventType|string>  $types
     * @return list<EventType> the types as they now are
     */
    public function declare(array $types): array
    {
        return $this->transport->putEventTypes(
            array_map(static fn (EventType|string $type): EventType => is_string($type) ? new EventType($type) : $type, $types),
            $this->scope->required(),
        );
    }

    /**
     * @return list<EventType>
     */
    public function all(): array
    {
        return $this->transport->listEventTypes($this->scope->required());
    }
}
