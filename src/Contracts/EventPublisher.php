<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Contracts;

use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Relay;

/**
 * How `Relay::publish()` hands an event over. The default puts a job on the queue, so
 * the caller's request never waits for Relay; `relay.publish.sync` and `Relay::fake()`
 * bind one that calls the transport at once. Bind your own to publish through an outbox
 * table or a different bus.
 *
 * The event always arrives with an idempotency key ({@see Relay::publish()}
 * generates one when the caller gave none), so an implementation may deliver it more
 * than once without publishing twice.
 */
interface EventPublisher
{
    /** `$project` is what goes out as `Relay-Project`; null with a project key. */
    public function publish(NewEvent $event, ?string $project = null): void;
}
