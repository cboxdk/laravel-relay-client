<?php

declare(strict_types=1);

use Cbox\Relay\Client\Data\BrowserSubscription;
use Cbox\Relay\Client\Data\Finding;
use Cbox\Relay\Client\Data\InboundAttachment;
use Cbox\Relay\Client\Data\InboundRoute;
use Cbox\Relay\Client\Data\InboundRouteType;
use Cbox\Relay\Client\Data\JudgeInput;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\PushUrgency;
use Cbox\Relay\Client\Data\SuppressionReason;
use Cbox\Relay\Client\Data\TemplateReference;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\SentPush;
use PHPUnit\Framework\AssertionFailedError;

beforeEach(function (): void {
    $this->relay = Relay::fake();
    $this->relay->seedScope('ws_1', 'Acme');
    $this->scoped = Relay::forScope('ws_1');
});

function order(string $to = 'ann@example.com'): OutgoingMail
{
    return new OutgoingMail(new MailAddress('hello@acme.dk', 'Acme'), [$to], 'Your order', text: 'Thanks');
}

// The judge

it('judges content, scores nothing by default, and knows content it saw before', function (): void {
    $first = $this->scoped->judge(html: '<p>Hi</p>', subject: 'Welcome', from: 'hello@acme.dk', template: new TemplateReference('welcome', '3'));
    $again = $this->scoped->judge(html: '<p>Hi</p>', subject: 'Welcome', from: 'hello@acme.dk', template: new TemplateReference('welcome', '3'));
    $other = $this->scoped->judge(html: '<p>Hi</p>', subject: 'Welcome', from: 'hello@acme.dk', template: new TemplateReference('welcome', '4'));

    expect($first->created)->toBeTrue()
        ->and($first->kind)->toBe('template')
        ->and($first->judgedBy)->toBe('rules')
        ->and($first->highest())->toBe(0.0)
        ->and(array_keys($first->verdicts))->toBe(['phishing', 'brand_impersonation', 'link_mismatch', 'credential_request', 'auto_reply', 'unsubscribe_intent'])
        ->and($again->created)->toBeFalse()
        ->and($again->id)->toBe($first->id)
        ->and($other->id)->not->toBe($first->id)
        ->and($this->scoped->judgment($first->id)?->created)->toBeFalse()
        ->and($this->scoped->judgment('jdg_missing'))->toBeNull();

    $this->relay->assertJudged(fn (JudgeInput $input, ?string $scope): bool => $input->from?->address === 'hello@acme.dk' && $input->template?->version === '3' && $scope === 'ws_1');
});

it('answers the judgment the test asked for', function (): void {
    $this->relay->judgeAs(['phishing' => 0.9], 'marketing', [new Finding('phishing', 2, 'urgent_wording', 0.9, 'act now')], 'Acme Bank');

    $judgment = $this->scoped->judge(text: 'Act now');

    expect($judgment->score('phishing'))->toBe(0.9)
        ->and($judgment->score('link_mismatch'))->toBe(0.0)
        ->and($judgment->category)->toBe('marketing')
        ->and($judgment->brand)->toBe('Acme Bank')
        ->and($judgment->findings[0]->rule)->toBe('urgent_wording')
        ->and($judgment->kind)->toBe('mail');
});

it('refuses to judge nothing, and fails its judge assertions when they do not hold', function (): void {
    $this->relay->assertNothingJudged();

    expect(fn () => $this->scoped->judge(subject: 'Empty'))->toThrow(RelayInvalid::class)
        ->and(fn () => $this->relay->assertJudged())->toThrow(AssertionFailedError::class);

    $this->scoped->judge(text: 'Hi');

    expect(fn () => $this->relay->assertNothingJudged())->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->relay->assertJudged(fn (JudgeInput $input): bool => $input->text === 'Bye'))->toThrow(AssertionFailedError::class);
});

// Inbound mail

it('claims a subdomain at once, a domain after DNS and a prefix after approval', function (): void {
    $routes = $this->scoped->inboundRoutes();

    $subdomain = $routes->register(InboundRouteType::Subdomain, 'acme.cbox.email');
    $domain = $routes->register(InboundRouteType::Domain, 'Acme.dk');
    $prefix = $routes->register(InboundRouteType::Prefix, 'cbox.email', 'r-');

    expect($subdomain->active())->toBeTrue()
        ->and($subdomain->verification)->toBeNull()
        ->and($domain->status)->toBe('pending')
        ->and($domain->pattern)->toBe('*@acme.dk')
        ->and($domain->verification?->type)->toBe('TXT')
        ->and($prefix->pattern)->toBe('r-*@cbox.email')
        ->and($prefix->status)->toBe('pending')
        ->and($routes->list())->toHaveCount(3);

    $this->relay->dnsPublished(false);
    expect($routes->verify($domain->id)->active())->toBeFalse();

    $this->relay->dnsPublished();
    expect($routes->verify($domain->id)->active())->toBeTrue()
        ->and($routes->verify($prefix->id)->active())->toBeFalse();

    $this->relay->activateInboundRoute($prefix->id);
    expect($routes->list()[2]->active())->toBeTrue();

    $routes->remove($subdomain->id);
    expect($routes->list())->toHaveCount(2);

    $this->relay->assertInboundRouteRegistered('*@acme.dk', fn (InboundRoute $route): bool => $route->active());
    expect(fn () => $this->relay->assertInboundRouteRegistered('*@acme.cbox.email'))->toThrow(AssertionFailedError::class);
});

it('gives a pattern one owner and a prefix route a prefix', function (): void {
    $this->relay->seedScope('ws_2');
    $this->scoped->inboundRoutes()->register(InboundRouteType::Domain, 'acme.dk');

    expect(fn () => Relay::forScope('ws_2')->inboundRoutes()->register(InboundRouteType::Domain, 'acme.dk'))->toThrow(RelayInvalid::class)
        ->and(fn () => $this->scoped->inboundRoutes()->register(InboundRouteType::Prefix, 'cbox.email'))->toThrow(RelayInvalid::class)
        ->and(fn () => $this->scoped->inboundRoutes()->verify('irt_missing'))->toThrow(RelayNotFound::class)
        ->and(fn () => $this->scoped->inboundRoutes()->remove('irt_missing'))->toThrow(RelayNotFound::class);
});

it('lists received mail as summaries, reads one with content and erases it', function (): void {
    $this->travelTo('2026-11-12 10:00:00');
    $old = $this->relay->seedInbound('ws_1', subject: 'Old', receivedAt: new DateTimeImmutable('2026-11-11 10:00:00'));
    $new = $this->relay->seedInbound('ws_1', 'hello@acme.dk', 'ann@example.com', 'New', 'Hi there', '<p>Hi there</p>', attachments: [new InboundAttachment('att_1', 'a.pdf', 'application/pdf', 900)], headers: ['subject' => 'New']);
    $this->relay->seedInbound('ws_2', subject: 'Theirs');

    $page = $this->scoped->inbound()->list();

    expect($page)->toHaveCount(2)
        ->and($page->items[0]->subject)->toBe('New')
        ->and($page->items[0]->text)->toBeNull()
        ->and($page->items[0]->attachments[0]->url)->toBeNull()
        ->and(Relay::inbound()->list())->toHaveCount(3);

    $mail = $this->scoped->inbound()->find($new->id);

    expect($mail?->text)->toBe('Hi there')
        ->and($mail?->html)->toBe('<p>Hi there</p>')
        ->and($mail?->headers)->toBe(['subject' => 'New'])
        ->and($mail?->attachments[0]->url)->toStartWith('https://')
        ->and($mail?->from?->address)->toBe('ann@example.com')
        ->and($mail?->parsed())->toBeTrue()
        ->and(Relay::forScope('ws_2')->inbound()->find($new->id))->toBeNull();

    $this->scoped->inbound()->erase($old->id);

    $this->relay->assertInboundErased($old->id);
    expect($this->scoped->inbound()->find($old->id))->toBeNull()
        ->and(fn () => $this->scoped->inbound()->erase($old->id))->toThrow(RelayNotFound::class)
        ->and(fn () => $this->relay->assertInboundErased($new->id))->toThrow(AssertionFailedError::class);
});

// Web Push

it('gives each scope a push key of its own', function (): void {
    $this->relay->seedScope('ws_2');

    expect($this->scoped->pushKey())->not->toBe(Relay::forScope('ws_2')->pushKey())
        ->and($this->scoped->pushKey())->toBe($this->scoped->pushKey());
});

it('remembers a browser once, lists by reference and forgets', function (): void {
    $browser = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => ['p256dh' => 'P', 'auth' => 'A']];
    $subscriptions = $this->scoped->pushSubscriptions();

    $first = $subscriptions->subscribe($browser, 'contact-9');
    $again = $subscriptions->subscribe(BrowserSubscription::fromArray($browser), 'contact-9');
    $other = $subscriptions->subscribe(new BrowserSubscription('https://updates.push.services.mozilla.com/x', 'P', 'A'), 'contact-10');

    expect($first->created)->toBeTrue()
        ->and($first->service)->toBe('fcm.googleapis.com')
        ->and($again->created)->toBeFalse()
        ->and($again->id)->toBe($first->id)
        ->and($subscriptions->list())->toHaveCount(2)
        ->and($subscriptions->list('contact-10')[0]->id)->toBe($other->id);

    $subscriptions->unsubscribe($first->id);

    expect($subscriptions->list())->toHaveCount(1)
        ->and(fn () => $subscriptions->unsubscribe($first->id))->toThrow(RelayNotFound::class)
        ->and(fn () => $subscriptions->subscribe(['endpoint' => 'http://insecure.example/x', 'keys' => ['p256dh' => 'P', 'auth' => 'A']]))->toThrow(RelayInvalid::class);
});

it('queues a push message, and the test decides what became of it', function (): void {
    $subscription = $this->relay->seedPushSubscription('ws_1', 'contact-9');

    $message = $this->scoped->push($subscription->id, ['title' => 'Shipped'], ttl: 60, urgency: PushUrgency::Low, topic: 'orders');

    expect($message->status)->toBe('queued')
        ->and($this->scoped->pushMessage($message->id)?->status)->toBe('queued')
        ->and($this->scoped->pushMessage('psm_missing'))->toBeNull();

    $this->relay->markPush($message->id, 'delivered', 201);
    expect($this->scoped->pushMessage($message->id)?->responseCode)->toBe(201)
        ->and($this->scoped->pushMessage($message->id)?->finishedAt)->not->toBeNull();

    $this->relay->markPush($message->id, 'expired', 410);
    expect($this->scoped->pushSubscriptions()->list())->toBe([]);

    $this->relay->assertPushed();
    $this->relay->assertPushed($subscription->id, fn (SentPush $push): bool => $push->ttl === 60 && $push->urgency === PushUrgency::Low && $push->topic === 'orders');
    $this->relay->assertPushedTimes(1);
});

it('refuses a push to an unknown subscription or with too much payload', function (): void {
    $subscription = $this->relay->seedPushSubscription('ws_1');

    expect(fn () => $this->scoped->push('psb_missing', 'Hi'))->toThrow(RelayNotFound::class)
        ->and(fn () => $this->scoped->push($subscription->id, str_repeat('x', 3001)))->toThrow(RelayInvalid::class);

    $this->relay->assertNothingPushed();

    expect(fn () => $this->relay->assertPushed())->toThrow(AssertionFailedError::class);
});

// Outbound mail

it('registers a sending domain with its records and verifies it', function (): void {
    $domains = $this->scoped->domains();
    $domain = $domains->register('Acme.dk');

    expect($domain->domain)->toBe('acme.dk')
        ->and($domain->status)->toBe('pending')
        ->and(array_map(fn ($record) => $record->purpose, $domain->records))->toBe(['dkim', 'spf', 'dmarc'])
        ->and($domain->records[0]->required)->toBeTrue()
        ->and($domain->records[1]->required)->toBeFalse()
        ->and($domains->find($domain->id)?->id)->toBe($domain->id)
        ->and($domains->find('sdm_missing'))->toBeNull()
        ->and(fn () => $domains->register('acme.dk'))->toThrow(RelayInvalid::class);

    $this->relay->dnsPublished(false);
    expect($domains->verify($domain->id)->status)->toBe('pending');

    $this->relay->dnsPublished();
    expect($domains->verify($domain->id)->verified())->toBeTrue();

    $this->relay->dnsPublished(false);
    expect($domains->verify($domain->id)->status)->toBe('failed');

    $domains->remove($domain->id);
    expect($domains->list())->toBe([]);
});

it('keeps a suppression list where the first reason stands', function (): void {
    $suppressions = $this->scoped->suppressions();

    $first = $suppressions->add('Ann@Example.com', SuppressionReason::Unsubscribe);
    $again = $suppressions->add('ann@example.com', SuppressionReason::Manual);
    $suppressions->add('bob@example.com');

    expect($first->created)->toBeTrue()
        ->and($first->address)->toBe('ann@example.com')
        ->and($again->created)->toBeFalse()
        ->and($again->reason)->toBe('unsubscribe')
        ->and($suppressions->list())->toHaveCount(2)
        ->and($suppressions->list()->items[0]->reason)->toBe('manual')
        ->and($suppressions->list(limit: 1)->hasMore())->toBeTrue();

    $suppressions->remove('ann@example.com');

    expect($suppressions->list())->toHaveCount(1)
        ->and(fn () => $suppressions->remove('ann@example.com'))->toThrow(RelayNotFound::class);
});

it('records a sent mail and answers queued', function (): void {
    $mail = $this->scoped->sendMail(order());

    expect($mail->status)->toBe('queued')
        ->and($mail->accepted())->toBeTrue()
        ->and($mail->recipients[0]->status)->toBe('queued')
        ->and($this->scoped->mail($mail->id)?->id)->toBe($mail->id)
        ->and($this->scoped->mail('msg_missing'))->toBeNull();

    $this->relay->markMail($mail->id, 'sent');
    expect($this->scoped->mail($mail->id)?->sentAt)->not->toBeNull()
        ->and($this->scoped->mail($mail->id)?->recipients[0]->status)->toBe('sent');

    $this->relay->assertMailSent();
    $this->relay->assertMailSent(fn (OutgoingMail $sent, ?string $scope): bool => $sent->to === ['ann@example.com'] && $scope === 'ws_1');
    $this->relay->assertMailSentTimes(1);
    expect($this->relay->sentMail())->toHaveCount(1);
});

it('blocks a mail whose every recipient is suppressed and sends to the others', function (): void {
    $this->relay->seedSuppression('ws_1', 'ann@example.com', SuppressionReason::Bounce, 'msg_0');

    $blocked = $this->scoped->sendMail(order());
    $partly = $this->scoped->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com'], 'Hi', text: 'Hi', cc: ['bob@example.com']));

    expect($blocked->status)->toBe('blocked')
        ->and($blocked->accepted())->toBeFalse()
        ->and($blocked->reason)->not->toBeNull()
        ->and($partly->status)->toBe('queued')
        ->and($partly->recipients[0]->status)->toBe('suppressed')
        ->and($partly->recipients[1]->type)->toBe('cc')
        ->and($partly->recipients[1]->status)->toBe('queued');
});

it('answers a repeated send with the same idempotency key once', function (): void {
    $first = $this->scoped->sendMail(order(), 'mail-1');
    $again = $this->scoped->sendMail(order(), 'mail-1');

    expect($again->id)->toBe($first->id);
    $this->relay->assertMailSentTimes(1);
    $this->relay->assertCalledTimes(Operation::SendMail, 2);

    expect(fn () => $this->scoped->sendMail(order('bob@example.com'), 'mail-1'))->toThrow(RelayConflict::class);
});

it('refuses what the real service refuses a mail for', function (OutgoingMail $mail): void {
    expect(fn () => $this->scoped->sendMail($mail))->toThrow(RelayInvalid::class);

    $this->relay->assertNoMailSent();
})->with([
    'no content' => [fn () => new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com'], 'Hi')],
    'no recipient' => [fn () => new OutgoingMail(new MailAddress('hello@acme.dk'), [], 'Hi', text: 'Hi')],
    'a header it may not set' => [fn () => new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com'], 'Hi', text: 'Hi', headers: ['Sender' => 'x@acme.dk'])],
    'too many recipients' => [fn () => new OutgoingMail(new MailAddress('hello@acme.dk'), array_map(fn (int $i): string => "u{$i}@example.com", range(1, 51)), 'Hi', text: 'Hi')],
]);

it('holds the sender to a verified domain once the scope has domains', function (): void {
    $this->relay->seedDomain('ws_1', 'acme.dk', 'pending');

    expect(fn () => $this->scoped->sendMail(order()))->toThrow(RelayInvalid::class);

    $this->relay->seedDomain('ws_1', 'acme.dk');

    expect($this->scoped->sendMail(order())->status)->toBe('queued');
});

it('fails its mail assertions when they do not hold', function (): void {
    $this->relay->assertNoMailSent();

    expect(fn () => $this->relay->assertMailSent())->toThrow(AssertionFailedError::class);

    $this->scoped->sendMail(order());

    expect(fn () => $this->relay->assertNoMailSent())->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->relay->assertMailSent(fn (OutgoingMail $mail): bool => $mail->subject === 'Other'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->relay->assertMailSentTimes(2))->toThrow(AssertionFailedError::class);
});

it('acts as a key with every ability by default', function (): void {
    expect(Relay::whoami()->can('events:publish'))->toBeTrue()
        ->and(Relay::whoami()->can('mail:send'))->toBeTrue()
        ->and(Relay::whoami()->key->abilities)->toHaveCount(14);
});
