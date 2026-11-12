<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\MessageStore;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Models\RelayMessage;
use Cbox\Relay\Client\Models\RelayMessageEvent;
use Cbox\Relay\Client\RelayServiceProvider;
use Cbox\Relay\Client\Store\DatabaseMessageStore;
use Cbox\Relay\Client\Store\NullMessageStore;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;
use Cbox\Relay\Client\Webhooks\WebhookSignature;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/**
 * @param  array<string, mixed>  $data
 */
function mailEvent(string $type, array $data, string $deliveryId): IncomingWebhook
{
    return new IncomingWebhook('evt_'.$deliveryId, $type, $data, occurredAt: new DateTimeImmutable('2026-11-12T10:00:00+00:00'), deliveryId: $deliveryId);
}

function enableRelayStore(): void
{
    config()->set('relay.store.enabled', true);
    (require __DIR__.'/../../database/migrations/create_relay_messages_tables.php.stub')->up();
    // What the provider does at boot when the store is on.
    (new RelayServiceProvider(app()))->boot();
}

it('keeps nothing and needs no table while the store is off', function (): void {
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');

    expect(app(MessageStore::class))->toBeInstanceOf(NullMessageStore::class)
        ->and(Schema::hasTable('relay_messages'))->toBeFalse();

    // Sending and an event both work with no table behind them.
    Relay::forProject('ws_1')->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com'], 'Hi', text: 'Hi'));
    expect(app(MessageStore::class)->recordEvent(mailEvent('mail.delivered', ['id' => 'msg_1', 'recipient' => 'ann@example.com'], 'whd_1')))->toBeTrue();
});

it('keeps a sent mail once, with its subject as the config says', function (string $mode, ?string $kept): void {
    enableRelayStore();
    config()->set('relay.store.subject', $mode);
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');
    $outgoing = new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com', 'bo@example.com'], 'Your order', text: 'Thanks', cc: ['cy@example.com']);

    $mail = Relay::forProject('ws_1')->sendMail($outgoing, 'order-1');
    // The same idempotency key: Relay answers the same mail, and it is still one row.
    Relay::forProject('ws_1')->sendMail($outgoing, 'order-1');

    expect(app(MessageStore::class))->toBeInstanceOf(DatabaseMessageStore::class)
        ->and(RelayMessage::query()->count())->toBe(1)
        ->and(RelayMessage::query()->sole()->only(['id', 'project', 'connection', 'subject', 'from_address', 'recipients', 'status']))->toBe([
            'id' => $mail->id, 'project' => 'ws_1', 'connection' => null, 'subject' => $kept, 'from_address' => 'hello@acme.dk', 'recipients' => 3, 'status' => 'queued',
        ]);
})->with([
    'a hash by default' => ['hash', hash('sha256', 'Your order')],
    'the subject itself' => ['plain', 'Your order'],
    'nothing' => ['none', null],
    'anything else is a hash' => ['whatever', hash('sha256', 'Your order')],
]);

it('brings a mail up to date from its events, once per delivery', function (): void {
    enableRelayStore();
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');
    $mail = Relay::forProject('ws_1')->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com', 'bo@example.com'], 'Hi', text: 'Hi'));
    $store = app(MessageStore::class);

    expect($store->recordEvent(mailEvent('mail.delivered', ['id' => $mail->id, 'recipient' => 'ann@example.com'], 'whd_1')))->toBeTrue()
        // Relay retried the delivery: nothing is counted twice.
        ->and($store->recordEvent(mailEvent('mail.delivered', ['id' => $mail->id, 'recipient' => 'ann@example.com'], 'whd_1')))->toBeFalse()
        ->and($store->recordEvent(mailEvent('mail.bounced', ['id' => $mail->id, 'recipient' => 'bo@example.com', 'permanent' => true], 'whd_2')))->toBeTrue()
        // A delivery after the bounce does not make the mail look fine again.
        ->and($store->recordEvent(mailEvent('mail.delivered', ['id' => $mail->id, 'recipient' => 'cy@example.com'], 'whd_3')))->toBeTrue()
        ->and($store->recordEvent(mailEvent('mail.clicked', ['id' => $mail->id, 'url' => 'https://acme.dk'], 'whd_4')))->toBeTrue()
        // An open is counted; a delay is kept and changes nothing about the status.
        ->and($store->recordEvent(mailEvent('mail.opened', ['id' => $mail->id, 'opened_at' => '2026-11-12T10:00:00+00:00'], 'whd_7')))->toBeTrue()
        ->and($store->recordEvent(mailEvent('mail.deferred', ['id' => $mail->id, 'recipient' => 'cy@example.com', 'status' => '4.7.1'], 'whd_8')))->toBeTrue()
        // Not about an outbound mail, and a mail event with no id: accepted, nothing kept.
        ->and($store->recordEvent(mailEvent('push.delivered', ['id' => 'psh_1'], 'whd_5')))->toBeTrue()
        ->and($store->recordEvent(mailEvent('mail.delivered', [], 'whd_6')))->toBeTrue();

    $message = RelayMessage::query()->findOrFail($mail->id);

    expect($message->only(['status', 'delivered', 'bounced', 'complained', 'clicked', 'opened']))->toBe(['status' => 'bounced', 'delivered' => 2, 'bounced' => 1, 'complained' => 0, 'clicked' => 1, 'opened' => 1])
        ->and($message->last_event_at?->toIso8601String())->toBe('2026-11-12T10:00:00+00:00')
        ->and($message->events->pluck('type')->all())->toBe(['mail.delivered', 'mail.bounced', 'mail.delivered', 'mail.clicked', 'mail.opened', 'mail.deferred'])
        ->and($message->events->first()?->recipient)->toBe('ann@example.com')
        ->and($message->events->first()?->message->is($message))->toBeTrue()
        ->and(RelayMessageEvent::query()->count())->toBe(6);
});

it('keeps an event about a mail it never kept', function (): void {
    enableRelayStore();

    expect(app(MessageStore::class)->recordEvent(mailEvent('mail.complained', ['id' => 'msg_elsewhere', 'recipient' => 'ann@example.com'], 'whd_1')))->toBeTrue()
        ->and(RelayMessageEvent::query()->sole()->message_id)->toBe('msg_elsewhere')
        ->and(RelayMessage::query()->count())->toBe(0);
});

it('writes the store from the webhook route', function (): void {
    enableRelayStore();
    config()->set('relay.webhook.secret', 'whsec_default');
    Relay::webhookRoutes();
    $relay = $this->fakeRelay();
    $relay->seedProject('ws_1');
    $mail = Relay::forProject('ws_1')->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com'], 'Hi', text: 'Hi'));
    $body = (string) json_encode(['id' => 'evt_1', 'type' => 'mail.delivered', 'created_at' => '2026-11-12T10:00:00+00:00', 'delivery_id' => 'whd_1', 'data' => ['id' => $mail->id, 'project_id' => 'prj_1', 'recipient' => 'ann@example.com']]);
    $post = fn () => $this->call('POST', '/relay/webhook', server: ['HTTP_X_CBOX_SIGNATURE' => WebhookSignature::header($body, 'whsec_default'), 'CONTENT_TYPE' => 'application/json'], content: $body);

    $post()->assertNoContent();
    $post()->assertOk();

    expect(RelayMessage::query()->findOrFail($mail->id)->only(['status', 'delivered']))->toBe(['status' => 'delivered', 'delivered' => 1]);
});

it('notes which mailable a mail sent through the transport came from', function (): void {
    enableRelayStore();
    config()->set('mail.default', 'relay');
    config()->set('mail.mailers.relay', ['transport' => 'relay']);
    config()->set('mail.from', ['address' => 'hello@acme.dk', 'name' => 'Acme']);
    config()->set('relay.project', 'ws_1');
    $this->fakeRelay()->seedProject('ws_1');

    Mail::to('ann@example.com')->send(new class extends Mailable
    {
        public function build(): static
        {
            return $this->subject('Welcome')->html('<p>Hi</p>');
        }
    });

    $message = RelayMessage::query()->sole();

    expect($message->id)->toStartWith('msg_')
        ->and($message->project)->toBe('ws_1')
        ->and($message->subject)->toBe(hash('sha256', 'Welcome'))
        ->and($message->mailable)->toContain('Mailable@anonymous');
});

it('publishes its migration on request and never loads it', function (): void {
    expect(Schema::hasTable('relay_messages'))->toBeFalse()
        ->and(array_keys(RelayServiceProvider::pathsToPublish(RelayServiceProvider::class, 'relay-migrations')))->toHaveCount(1)
        ->and(array_keys(RelayServiceProvider::pathsToPublish(RelayServiceProvider::class, 'relay-config')))->toHaveCount(1);
});
