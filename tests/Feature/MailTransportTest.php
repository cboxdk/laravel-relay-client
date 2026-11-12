<?php

declare(strict_types=1);

use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayProjectRequired;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Mail\EmailMapper;
use Cbox\Relay\Client\Mail\RelayMailTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('mail.default', 'relay');
    config()->set('mail.mailers.relay', ['transport' => 'relay']);
    config()->set('mail.from', ['address' => 'hello@acme.dk', 'name' => 'Acme']);
    config()->set('relay.project', 'ws_1');
});

function fullEmail(): Email
{
    return (new Email)
        ->from(new Address('hello@acme.dk', 'Acme'))
        ->to(new Address('ann@example.com', 'Ann'), 'bob@example.com')
        ->cc('carol@example.com')
        ->bcc('dave@example.com')
        ->replyTo('support@acme.dk', 'second@acme.dk')
        ->subject('Your order')
        ->html('<p>Thanks</p>')
        ->text('Thanks')
        ->attach('paid', 'receipt.txt', 'text/plain');
}

it('registers the relay transport with Laravel\'s mail manager', function (): void {
    expect(Mail::mailer('relay')->getSymfonyTransport())->toBeInstanceOf(RelayMailTransport::class)
        ->and((string) Mail::mailer('relay')->getSymfonyTransport())->toBe('relay');
});

it('turns a Symfony email into the structured mail', function (): void {
    $mail = EmailMapper::toOutgoingMail(fullEmail());

    expect($mail->from->address)->toBe('hello@acme.dk')
        ->and($mail->from->name)->toBe('Acme')
        ->and($mail->to)->toBe(['ann@example.com', 'bob@example.com'])
        ->and($mail->cc)->toBe(['carol@example.com'])
        ->and($mail->bcc)->toBe(['dave@example.com'])
        ->and($mail->replyTo)->toBe('support@acme.dk')
        ->and($mail->subject)->toBe('Your order')
        ->and($mail->html)->toBe('<p>Thanks</p>')
        ->and($mail->text)->toBe('Thanks')
        ->and($mail->headers)->toBe([])
        ->and($mail->attachments)->toHaveCount(1)
        ->and($mail->attachments[0]->name)->toBe('receipt.txt')
        ->and($mail->attachments[0]->type)->toBe('text/plain')
        ->and($mail->attachments[0]->content)->toBe('paid')
        ->and($mail->attachments[0]->toArray()['content'])->toBe(base64_encode('paid'));
});

it('passes on the headers Relay allows and drops the rest', function (): void {
    $email = fullEmail();
    $email->getHeaders()
        ->addTextHeader('X-Order', 'o_1')
        ->addTextHeader('List-Unsubscribe', '<https://acme.dk/u/1>')
        ->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click')
        ->addTextHeader('Precedence', 'bulk')
        ->addIdHeader('In-Reply-To', 'abc@example.com')
        ->addIdHeader('References', ['abc@example.com', 'def@example.com'])
        ->addTextHeader('Return-Path-Hint', 'nope')
        ->addTextHeader('Received', 'from nowhere')
        ->addTextHeader('X-Long', str_repeat('word ', 40));

    $headers = EmailMapper::toOutgoingMail($email)->headers;

    expect($headers)->toHaveKeys(['X-Order', 'List-Unsubscribe', 'List-Unsubscribe-Post', 'Precedence', 'In-Reply-To', 'References', 'X-Long'])
        ->and($headers)->not->toHaveKeys(['Return-Path-Hint', 'Received', 'From', 'To', 'Subject', 'Reply-To', 'Cc', 'Bcc', 'Date', 'Message-ID'])
        ->and($headers['X-Order'])->toBe('o_1')
        ->and($headers['In-Reply-To'])->toBe('<abc@example.com>')
        ->and($headers['References'])->toBe('<abc@example.com> <def@example.com>')
        ->and($headers['X-Long'])->not->toContain("\n")
        ->and(array_filter(array_keys($headers), fn (string $name): bool => ! OutgoingMail::allowsHeader($name)))->toBe([]);
});

it('knows which headers a sender may set', function (string $name, bool $allowed): void {
    expect(OutgoingMail::allowsHeader($name))->toBe($allowed);
})->with([
    ['X-Anything', true], ['x-lower', true], ['List-Unsubscribe', true], ['LIST-ID', true], ['Auto-Submitted', true],
    ['From', false], ['Sender', false], ['Return-Path', false], ['DKIM-Signature', false], ['X-', false], ['X-Bad Name', false],
]);

it('sends a Laravel mail through the fake', function (): void {
    $relay = Relay::fake();
    $relay->seedProject('ws_1');

    Mail::html('<p>Thanks</p>', fn (Message $message) => $message->to('ann@example.com')->subject('Your order'));

    $relay->assertMailSentTimes(1);
    $relay->assertMailSent(fn (OutgoingMail $mail, ?string $project): bool => $mail->from->address === 'hello@acme.dk'
        && $mail->from->name === 'Acme'
        && $mail->to === ['ann@example.com']
        && $mail->subject === 'Your order'
        && $mail->html === '<p>Thanks</p>'
        && $project === 'ws_1');
});

it('takes the project and the idempotency key from control headers and never passes them on', function (): void {
    $relay = Relay::fake();
    $relay->seedProject('ws_1');
    $relay->seedProject('ws_2');

    Mail::raw('Thanks', function (Message $message): void {
        $message->to('ann@example.com')->subject('Your order');
        $message->getHeaders()->addTextHeader('X-Relay-Project', 'ws_2')->addTextHeader('X-Relay-Idempotency-Key', 'order-mail:o_1')->addTextHeader('X-Order', 'o_1');
    });

    $relay->assertMailSent(fn (OutgoingMail $mail, ?string $project): bool => $project === 'ws_2' && $mail->headers === ['X-Order' => 'o_1'] && $mail->text === 'Thanks');

    expect($relay->calls()[0]->idempotencyKey)->toBe('order-mail:o_1');
});

it('uses the project of the mailer over the client\'s default', function (): void {
    config()->set('mail.mailers.relay', ['transport' => 'relay', 'project' => 'ws_mailer']);
    $relay = Relay::fake()->acceptAnyProject();

    Mail::raw('Thanks', fn (Message $message) => $message->to('ann@example.com')->subject('Hi'));

    $relay->assertMailSent(fn (OutgoingMail $mail, ?string $project): bool => $project === 'ws_mailer');
});

it('leaves the mail id and status on the sent message', function (): void {
    Event::fake([MessageSent::class]);
    $relay = Relay::fake();
    $relay->seedProject('ws_1');

    Mail::raw('Thanks', fn (Message $message) => $message->to('ann@example.com')->subject('Hi'));

    Event::assertDispatched(MessageSent::class, fn (MessageSent $event): bool => str_starts_with((string) $event->message->getHeaders()->get('X-Relay-Mail-Id')?->getBodyAsString(), 'msg_')
        && $event->message->getHeaders()->get('X-Relay-Mail-Status')?->getBodyAsString() === 'queued');
});

it('posts the structured body over HTTP', function (): void {
    Http::fake(['relay.test/v1/mail' => Http::response(['id' => 'msg_1', 'status' => 'queued', 'from' => 'hello@acme.dk', 'subject' => 'Your order', 'reason' => null, 'verdicts' => [], 'recipients' => [], 'created_at' => '2026-11-12T10:00:00Z', 'sent_at' => null], 202)]);

    Mail::mailer('relay')->getSymfonyTransport()->send(fullEmail());

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://relay.test/v1/mail'
        && $request->hasHeader('Relay-Project', 'ws_1')
        && json_decode($request->body(), true) === [
            'from' => ['address' => 'hello@acme.dk', 'name' => 'Acme'],
            'to' => ['ann@example.com', 'bob@example.com'],
            'cc' => ['carol@example.com'],
            'bcc' => ['dave@example.com'],
            'reply_to' => 'support@acme.dk',
            'subject' => 'Your order',
            'html' => '<p>Thanks</p>',
            'text' => 'Thanks',
            'attachments' => [['name' => 'receipt.txt', 'type' => 'text/plain', 'content' => 'cGFpZA==']],
        ]);
});

it('throws what Relay refuses, and needs a project like any other send', function (): void {
    $relay = Relay::fake();
    $relay->seedProject('ws_1');
    $relay->seedDomain('ws_1', 'other.dk');

    expect(fn () => Mail::raw('Thanks', fn (Message $message) => $message->to('ann@example.com')->subject('Hi')))->toThrow(RelayInvalid::class);

    config()->set('relay.project', null);

    expect(fn () => Mail::raw('Thanks', fn (Message $message) => $message->to('ann@example.com')->subject('Hi')))->toThrow(RelayProjectRequired::class);
});

it('refuses a mail without a sender', function (): void {
    EmailMapper::toOutgoingMail((new Email)->to('ann@example.com')->text('Hi'));
})->throws(TransportException::class);
