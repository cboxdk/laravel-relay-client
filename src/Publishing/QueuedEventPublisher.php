<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Publishing;

use Cbox\Relay\Client\Contracts\EventPublisher;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Jobs\PublishEvent;
use Cbox\Relay\Client\RelayConfig;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Publishes from the queue: a {@see PublishEvent} job is dispatched after the caller's
 * database transaction commits, on the connection and queue `relay.publish` names. An
 * event published inside a transaction that rolls back is never sent.
 */
class QueuedEventPublisher implements EventPublisher
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly RelayConfig $config,
    ) {}

    public function publish(NewEvent $event, ?string $scope = null): void
    {
        $job = PublishEvent::for($event, $scope, $this->config->publishTries(), $this->config->publishBackoff())
            ->onConnection($this->config->publishConnection())
            ->onQueue($this->config->publishQueue())
            ->afterCommit();

        $this->bus->dispatch($job);
    }
}
