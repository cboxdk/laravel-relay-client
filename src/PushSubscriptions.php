<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\BrowserSubscription;
use Cbox\Relay\Client\Data\PushSubscription;

/** The browsers subscribed to a project's push messages. Reached through `Relay::pushSubscriptions()`. */
class PushSubscriptions
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingProject $project = new ActingProject,
    ) {}

    /**
     * Remember a browser. `$subscription` is what `PushSubscription.toJSON()` gave in
     * the page, as a {@see BrowserSubscription} or as the decoded array; `$ref` is your
     * own reference for whose browser it is. A browser Relay knew keeps its id and gets
     * its keys updated (`$subscription->created` is then false).
     *
     * @param  BrowserSubscription|array<array-key, mixed>  $subscription
     */
    public function subscribe(BrowserSubscription|array $subscription, ?string $ref = null, ?string $idempotencyKey = null): PushSubscription
    {
        return $this->transport->createPushSubscription(
            is_array($subscription) ? BrowserSubscription::fromArray($subscription) : $subscription,
            $ref,
            $idempotencyKey,
            $this->project->ref,
        );
    }

    /**
     * The newest 500, or only those with `$ref`.
     *
     * @return list<PushSubscription>
     */
    public function list(?string $ref = null): array
    {
        return $this->transport->listPushSubscriptions($ref, $this->project->ref);
    }

    public function unsubscribe(string $id): void
    {
        $this->transport->deletePushSubscription($id, $this->project->ref);
    }
}
