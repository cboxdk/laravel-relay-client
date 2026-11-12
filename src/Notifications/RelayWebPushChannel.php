<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Notifications;

use Cbox\Relay\Client\Data\PushMessage;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Relay;
use Illuminate\Contracts\Container\Container;
use Illuminate\Notifications\Notification;
use LogicException;

/**
 * A Laravel notification channel that sends Web Push through Relay.
 *
 * ```php
 * public function via(object $notifiable): array { return [RelayWebPushChannel::class]; }
 * public function toRelayWebPush(object $notifiable): WebPushMessage { … }
 * ```
 *
 * The notifiable's `routeNotificationForRelayWebPush()` returns the id of one of its
 * push subscriptions (`psb_…`), or several. Each gets its own message. A subscription
 * Relay no longer knows (404) is skipped so the others still get theirs; every other
 * failure is thrown. The call is made where the notification is sent from: implement
 * `ShouldQueue` on the notification to keep it out of a request.
 */
class RelayWebPushChannel
{
    /** The name `routeNotificationFor()` is asked with, so the method is `routeNotificationForRelayWebPush`. */
    public const string ROUTE = 'relayWebPush';

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<PushMessage> the messages Relay stored, one per subscription that still exists
     */
    public function send(object $notifiable, Notification $notification): array
    {
        if (! method_exists($notification, 'toRelayWebPush')) {
            throw new LogicException($notification::class.' is sent through RelayWebPushChannel and needs a toRelayWebPush($notifiable) method.');
        }

        $message = $notification->toRelayWebPush($notifiable);

        if (! $message instanceof WebPushMessage) {
            throw new LogicException($notification::class.'::toRelayWebPush() must return a '.WebPushMessage::class.'.');
        }

        // Resolved per send, so a fake bound after this channel was built is the one used.
        $relay = $this->container->make(Relay::class);

        if ($message->scope !== null) {
            $relay = $relay->forScope($message->scope);
        }

        $sent = [];

        foreach ($this->subscriptions($notifiable, $notification) as $subscription) {
            try {
                $sent[] = $relay->push($subscription, $message->payload, $message->ttl, $message->urgency, $message->topic);
            } catch (RelayNotFound $e) {
                // A scope that does not exist is a mistake to hear about; a subscription that is gone is not.
                if (str_ends_with($e->problem->type ?? '', 'scope_not_found')) {
                    throw $e;
                }
            }
        }

        return $sent;
    }

    /**
     * @return list<string>
     */
    private function subscriptions(object $notifiable, Notification $notification): array
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            return [];
        }

        $route = $notifiable->routeNotificationFor(self::ROUTE, $notification);
        $ids = [];

        foreach (is_iterable($route) ? $route : [$route] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
