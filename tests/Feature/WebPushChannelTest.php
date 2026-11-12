<?php

declare(strict_types=1);

use Cbox\Relay\Client\Data\PushUrgency;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Notifications\RelayWebPushChannel;
use Cbox\Relay\Client\Notifications\WebPushMessage;
use Cbox\Relay\Client\Testing\SentPush;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

class OrderShipped extends Notification
{
    public function __construct(public ?string $scope = null) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [RelayWebPushChannel::class];
    }

    public function toRelayWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(['title' => 'Your order shipped'], ttl: 3600, urgency: PushUrgency::High, topic: 'orders', scope: $this->scope);
    }
}

class WithoutPush extends Notification
{
    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [RelayWebPushChannel::class];
    }
}

class Customer
{
    use Notifiable;

    /** @param string|list<string>|null $subscriptions */
    public function __construct(public string|array|null $subscriptions) {}

    public function getKey(): int
    {
        return 1;
    }

    /** @return string|list<string>|null */
    public function routeNotificationForRelayWebPush(Notification $notification): string|array|null
    {
        return $this->subscriptions;
    }
}

beforeEach(function (): void {
    config()->set('relay.scope', 'ws_1');
    $this->relay = Relay::fake();
    $this->relay->seedScope('ws_1');
});

it('sends one push message to the notifiable\'s subscription', function (): void {
    $subscription = $this->relay->seedPushSubscription('ws_1', 'customer-1');

    (new Customer($subscription->id))->notify(new OrderShipped);

    $this->relay->assertPushedTimes(1);
    $this->relay->assertPushed($subscription->id, fn (SentPush $push): bool => $push->payload === ['title' => 'Your order shipped']
        && $push->ttl === 3600
        && $push->urgency === PushUrgency::High
        && $push->topic === 'orders'
        && $push->scope === 'ws_1');
});

it('sends to every subscription the notifiable routes to, once each', function (): void {
    $a = $this->relay->seedPushSubscription('ws_1');
    $b = $this->relay->seedPushSubscription('ws_1');

    $sent = app(RelayWebPushChannel::class)->send(new Customer([$a->id, $b->id, $a->id, '']), new OrderShipped);

    expect($sent)->toHaveCount(2)->and($sent[0]->subscriptionId)->toBe($a->id)->and($sent[1]->status)->toBe('queued');
    $this->relay->assertPushed($a->id);
    $this->relay->assertPushed($b->id);
    $this->relay->assertPushedTimes(2);
});

it('skips a subscription Relay no longer knows and still sends to the others', function (): void {
    $known = $this->relay->seedPushSubscription('ws_1');

    $sent = app(RelayWebPushChannel::class)->send(new Customer(['psb_gone', $known->id]), new OrderShipped);

    expect($sent)->toHaveCount(1);
    $this->relay->assertPushed($known->id);
});

it('sends for the scope the message names', function (): void {
    $this->relay->seedScope('ws_2');
    $subscription = $this->relay->seedPushSubscription('ws_2');

    (new Customer($subscription->id))->notify(new OrderShipped('ws_2'));

    $this->relay->assertPushed($subscription->id, fn (SentPush $push): bool => $push->scope === 'ws_2');
});

it('does not hide a scope that does not exist, or Relay being down', function (): void {
    $subscription = $this->relay->seedPushSubscription('ws_1');

    expect(fn () => (new Customer($subscription->id))->notify(new OrderShipped('ws_missing')))->toThrow(RelayNotFound::class);

    $this->relay->failWith(new RelayUnavailable);

    expect(fn () => (new Customer($subscription->id))->notify(new OrderShipped))->toThrow(RelayUnavailable::class);
});

it('sends nothing to a notifiable without subscriptions', function (): void {
    (new Customer(null))->notify(new OrderShipped);
    (new Customer([]))->notify(new OrderShipped);

    $this->relay->assertNothingPushed();
});

it('routes an on-demand notification', function (): void {
    $subscription = $this->relay->seedPushSubscription('ws_1');

    Notifications::route(RelayWebPushChannel::ROUTE, $subscription->id)->notify(new OrderShipped);

    expect(new AnonymousNotifiable)->toBeInstanceOf(AnonymousNotifiable::class);
    $this->relay->assertPushed($subscription->id);
});

it('says what a notification is missing', function (): void {
    (new Customer('psb_1'))->notify(new WithoutPush);
})->throws(LogicException::class, 'toRelayWebPush');
