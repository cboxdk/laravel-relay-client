<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Publishing;

use Cbox\Relay\Client\Contracts\EventPublisher;
use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\NewEvent;

/** Publishes in the caller's own process: the HTTP call is made before `publish()` returns, and its failure is the caller's. */
class ImmediateEventPublisher implements EventPublisher
{
    public function __construct(private readonly RelayTransport $transport) {}

    public function publish(NewEvent $event, ?string $project = null): void
    {
        $this->transport->publishEvent($event, $project);
    }
}
