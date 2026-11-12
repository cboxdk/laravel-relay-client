<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\Mail as RelayMail;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\MailAttachment;
use Cbox\Relay\Client\Data\MailFilter;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\TrackingChanges;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Testing\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;

/*
| What a mail can carry and what can be done with it since Relay's API of 12 November
| 2026: tags, metadata, inline images, a send time, open tracking, a list, a batch and a
| cancel. Over HTTP as the OpenAPI document describes them, in the fake, and through
| Laravel's mailer.
*/

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function optionsMail(array $extra = []): array
{
    return [
        'id' => 'msg_1', 'status' => 'queued', 'from' => 'hello@acme.dk', 'subject' => 'Your order', 'reason' => null,
        'tracking' => ['clicks' => false, 'opens' => false], 'tags' => [], 'metadata' => [], 'verdicts' => [],
        'recipients' => [['address' => 'ann@example.com', 'type' => 'to', 'status' => 'queued', 'detail' => null]],
        'created_at' => '2026-11-12T10:00:00Z', 'send_at' => null, 'sent_at' => null,
        ...$extra,
    ];
}

function orderMail(string $to = 'ann@example.com', ?string $html = '<p>Thanks</p>'): OutgoingMail
{
    return new OutgoingMail(new MailAddress('hello@acme.dk', 'Acme'), [$to], 'Your order', html: $html, text: $html === null ? 'Thanks' : null);
}

beforeEach(fn () => Http::preventStrayRequests());

// Over HTTP

it('sends tags, metadata, a send time, open tracking and an inline image, and reads them back', function (): void {
    Http::fake(['relay.test/v1/mail' => Http::response(optionsMail([
        'status' => 'scheduled', 'tracking' => ['clicks' => true, 'opens' => true], 'tags' => ['invoice', 'billing'],
        'metadata' => ['invoice_id' => 'inv_1'], 'send_at' => '2026-12-01T08:00:00Z',
    ]), 202)]);

    $mail = new OutgoingMail(
        new MailAddress('hello@acme.dk'), ['ann@example.com'], 'Your order',
        html: '<img src="cid:logo">',
        attachments: [new MailAttachment('logo.png', 'image/png', 'PNG', 'logo'), new MailAttachment('receipt.txt', 'text/plain', 'paid')],
        trackClicks: true,
        trackOpens: true,
        tags: ['invoice', 'billing'],
        metadata: ['invoice_id' => 'inv_1'],
        sendAt: new DateTimeImmutable('2026-12-01 09:00:00', new DateTimeZone('Europe/Copenhagen')),
    );

    $stored = app(RelayTransport::class)->sendMail($mail, 'k1', 'ws_1');

    expect([$stored->status, $stored->scheduled(), $stored->accepted(), $stored->tracksClicks, $stored->tracksOpens])->toBe(['scheduled', true, true, true, true])
        ->and($stored->tags)->toBe(['invoice', 'billing'])
        ->and($stored->metadata)->toBe(['invoice_id' => 'inv_1'])
        ->and($stored->sendAt?->format('Y-m-d H:i'))->toBe('2026-12-01 08:00');

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tracking'] === ['clicks' => true, 'opens' => true]
            && $body['tags'] === ['invoice', 'billing']
            && $body['metadata'] === ['invoice_id' => 'inv_1']
            // UTC on the wire, whatever zone the moment was given in.
            && str_starts_with($body['send_at'], '2026-12-01T08:00:00')
            && $body['attachments'][0] === ['name' => 'logo.png', 'type' => 'image/png', 'content' => base64_encode('PNG'), 'content_id' => 'logo']
            && ! array_key_exists('content_id', $body['attachments'][1]);
    });
});

it('leaves out of the body what a mail does not say, and reads a mail from a Relay that says less', function (): void {
    $bare = optionsMail();
    unset($bare['tags'], $bare['metadata'], $bare['send_at'], $bare['tracking']);
    Http::fake(['relay.test/v1/mail' => Http::response($bare, 202)]);

    $stored = app(RelayTransport::class)->sendMail(orderMail()->trackingOpens(false));

    expect([$stored->tags, $stored->metadata, $stored->sendAt, $stored->tracksOpens, $stored->scheduled()])->toBe([[], [], null, false, false]);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $body['tracking'] === ['opens' => false]
            && ! array_key_exists('tags', $body) && ! array_key_exists('metadata', $body) && ! array_key_exists('send_at', $body)
            // No project named: no header, and Relay uses the key's or the default one.
            && ! $request->hasHeader('Relay-Project');
    });
});

it('lists a project\'s mail with its filters and pages', function (): void {
    Http::fake(['relay.test/v1/mail*' => Http::response(['data' => [optionsMail(['tags' => ['invoice']])], 'next_cursor' => 'c2'])]);

    $page = Relay::forProject('ws_1')->mails(new MailFilter(
        status: 'sent', from: 'hello@acme.dk', to: 'ann@example.com', tag: 'invoice',
        createdAfter: new DateTimeImmutable('2026-11-01T00:00:00Z'), createdBefore: new DateTimeImmutable('2026-12-01T00:00:00Z'),
    ), 20, 'c1');

    expect($page)->toHaveCount(1)
        ->and($page->items[0])->toBeInstanceOf(RelayMail::class)
        ->and($page->items[0]->tags)->toBe(['invoice'])
        ->and($page->nextCursor)->toBe('c2')
        ->and((new MailFilter)->toQuery())->toBe([]);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://relay.test/v1/mail?')
            && $request->header('Relay-Project') === ['ws_1']
            && $query['status'] === 'sent' && $query['from'] === 'hello@acme.dk' && $query['to'] === 'ann@example.com' && $query['tag'] === 'invoice'
            && str_starts_with($query['created_after'], '2026-11-01T00:00:00') && str_starts_with($query['created_before'], '2026-12-01T00:00:00')
            && $query['limit'] === '20' && $query['cursor'] === 'c1';
    });
});

it('sends a batch and reads one result per mail, in order', function (): void {
    Http::fake(['relay.test/v1/mail/batch' => Http::response(['data' => [
        ['status' => 202, 'mail' => optionsMail()],
        ['status' => 422, 'problem' => ['type' => 'https://cboxrelay.com/problems/invalid_request', 'title' => 'Unprocessable content', 'status' => 422, 'detail' => 'The sender\'s domain is not verified.', 'errors' => ['from.address' => ['Not verified.']]]],
        ['status' => 429, 'problem' => ['type' => 'https://cboxrelay.com/problems/rate_limited', 'title' => 'Too many requests', 'status' => 429]],
    ]])]);

    $results = Relay::forProject('ws_1')->sendMailBatch([orderMail(), orderMail('bo@example.com'), orderMail('cy@example.com')], 'batch-1');

    expect($results)->toHaveCount(3)
        ->and([$results[0]->status, $results[0]->stored(), $results[0]->mail?->id, $results[0]->problem])->toBe([202, true, 'msg_1', null])
        ->and([$results[1]->status, $results[1]->stored(), $results[1]->problem?->detail])->toBe([422, false, 'The sender\'s domain is not verified.'])
        ->and($results[1]->problem?->errors)->toBe(['from.address' => ['Not verified.']])
        ->and([$results[2]->status, $results[2]->problem?->type])->toBe([429, 'https://cboxrelay.com/problems/rate_limited']);

    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://relay.test/v1/mail/batch'
            && $request->header('Idempotency-Key') === ['batch-1']
            && count($body['mails']) === 3
            && $body['mails'][1]['to'] === ['bo@example.com'];
    });
});

it('cancels a scheduled mail, and a mail that no longer waits is a conflict', function (): void {
    Http::fake([
        'relay.test/v1/mail/msg_1/cancel' => Http::response(optionsMail(['status' => 'cancelled'])),
        'relay.test/v1/mail/msg_2/cancel' => Http::response(['type' => 'https://cboxrelay.com/problems/mail_not_scheduled', 'title' => 'Conflict', 'status' => 409], 409),
    ]);

    $cancelled = Relay::forProject('ws_1')->cancelMail('msg_1');

    expect([$cancelled->status, $cancelled->accepted(), $cancelled->scheduled()])->toBe(['cancelled', false, false]);

    try {
        Relay::forProject('ws_1')->cancelMail('msg_2');
        $this->fail('Nothing was thrown.');
    } catch (RelayConflict $e) {
        expect($e->problem?->type)->toEndWith('mail_not_scheduled');
    }
});

it('turns open tracking on for a domain and reads it back', function (): void {
    $domain = ['id' => 'sdm_1', 'domain' => 'acme.dk', 'status' => 'verified', 'tracking' => ['clicks' => false, 'opens' => true, 'host' => null], 'records' => [], 'verified_at' => null, 'checked_at' => null, 'created_at' => '2026-11-12T10:00:00Z'];
    Http::fake(['relay.test/v1/domains/sdm_1' => Http::response($domain)]);

    expect(Relay::forProject('ws_1')->domains()->trackOpens('sdm_1')->tracking->opens)->toBeTrue()
        ->and((new TrackingChanges(clicks: false, opens: true))->toArray())->toBe(['tracking' => ['clicks' => false, 'opens' => true]])
        ->and((new TrackingChanges(opens: false))->toArray())->toBe(['tracking' => ['opens' => false]]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && json_decode($request->body(), true) === ['tracking' => ['opens' => true]]);
});

// In the fake

describe('in the fake', function (): void {
    beforeEach(function (): void {
        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->relay = Relay::fake();
        $this->relay->seedProject('ws_1');
        $this->scoped = Relay::forProject('ws_1');
    });

    it('keeps tags and metadata with the mail and lists by them', function (): void {
        $invoice = $this->scoped->sendMail(orderMail()->tagged(['invoice'])->withMetadata(['invoice_id' => 'inv_1']));
        $this->scoped->sendMail(orderMail('bo@example.com')->tagged(['welcome']));
        Relay::sendMail(orderMail('cy@example.com'));

        expect($this->scoped->mail($invoice->id)?->tags)->toBe(['invoice'])
            ->and($this->scoped->mail($invoice->id)?->metadata)->toBe(['invoice_id' => 'inv_1'])
            // Newest first, and only this project's.
            ->and(array_map(fn (RelayMail $mail): array => $mail->tags, $this->scoped->mails()->items))->toBe([['welcome'], ['invoice']])
            ->and($this->scoped->mails(new MailFilter(tag: 'invoice'))->items[0]->id)->toBe($invoice->id)
            ->and($this->scoped->mails(new MailFilter(to: 'BO@example.com')))->toHaveCount(1)
            ->and($this->scoped->mails(new MailFilter(from: 'other@acme.dk')))->toHaveCount(0)
            ->and($this->scoped->mails(new MailFilter(status: 'queued'), 1)->nextCursor)->not->toBeNull()
            // The mail sent with no project went to the default one.
            ->and(Relay::mails())->toHaveCount(1)
            ->and(Relay::forProject('default')->mails()->items[0]->recipients[0]->address)->toBe('cy@example.com');

        expect($this->relay->calls(Operation::ListMail))->not->toBeEmpty()
            ->and($this->relay->calls(Operation::ListMail)[0]->subject)->toBeInstanceOf(MailFilter::class);
    });

    it('holds a mail until its time, and cancels it once', function (): void {
        $later = $this->scoped->sendMail(orderMail()->sendingAt(new DateTimeImmutable('2026-11-14 08:00:00')));
        $past = $this->scoped->sendMail(orderMail()->sendingAt(new DateTimeImmutable('2026-11-11 08:00:00')));

        expect([$later->status, $later->sendAt?->format('Y-m-d H:i'), $later->scheduled()])->toBe(['scheduled', '2026-11-14 08:00', true])
            // A moment that has passed means now.
            ->and([$past->status, $past->sendAt])->toBe(['queued', null])
            ->and($this->scoped->mails(new MailFilter(status: 'scheduled')))->toHaveCount(1);

        $cancelled = $this->scoped->cancelMail($later->id);

        expect($cancelled->status)->toBe('cancelled')
            ->and($cancelled->recipients[0]->status)->toBe('failed')
            ->and(fn () => $this->scoped->cancelMail($later->id))->toThrow(RelayConflict::class)
            ->and(fn () => $this->scoped->cancelMail($past->id))->toThrow(RelayConflict::class)
            ->and(fn () => $this->scoped->cancelMail('msg_missing'))->toThrow(RelayNotFound::class);

        $this->relay->assertMailCancelled($later->id);
        expect(fn () => $this->relay->assertMailCancelled($past->id))->toThrow(AssertionFailedError::class);
    });

    it('accepts each mail of a batch on its own, and answers a repeat with the same results', function (): void {
        $this->relay->seedDomain('ws_1', 'acme.dk');
        $stranger = new OutgoingMail(new MailAddress('hello@other.dk'), ['bo@example.com'], 'Hi', text: 'Hi');

        $results = $this->scoped->sendMailBatch([orderMail(), $stranger, orderMail('cy@example.com')], 'batch-1');

        expect(array_map(fn ($result): int => $result->status, $results))->toBe([202, 422, 202])
            ->and($results[1]->problem?->errors)->toHaveKey('from.address')
            ->and($results[1]->stored())->toBeFalse()
            ->and($this->scoped->mails())->toHaveCount(2)
            // The same key, the same call: nothing new is stored.
            ->and($this->scoped->sendMailBatch([orderMail(), $stranger, orderMail('cy@example.com')], 'batch-1')[0]->mail?->id)->toBe($results[0]->mail?->id)
            ->and($this->scoped->mails())->toHaveCount(2)
            ->and(fn () => $this->scoped->sendMailBatch([]))->toThrow(RelayInvalid::class)
            ->and(fn () => $this->scoped->sendMailBatch(array_fill(0, 101, orderMail())))->toThrow(RelayInvalid::class);

        $this->relay->assertMailSentTimes(2);
    });

    it('tracks opens per domain, a mail may say otherwise, and a mail without HTML is never tracked', function (): void {
        $domain = $this->relay->seedDomain('ws_1', 'acme.dk');

        expect($this->scoped->sendMail(orderMail())->tracksOpens)->toBeFalse();

        expect($this->scoped->domains()->trackOpens($domain->id)->tracking->opens)->toBeTrue();
        $this->relay->assertDomainTracksOpens($domain->id);
        $this->relay->assertDomainTracksClicks($domain->id, false);

        expect($this->scoped->sendMail(orderMail())->tracksOpens)->toBeTrue()
            ->and($this->scoped->sendMail(orderMail()->trackingOpens(false))->tracksOpens)->toBeFalse()
            ->and($this->scoped->sendMail(orderMail(html: null))->tracksOpens)->toBeFalse()
            ->and($this->scoped->sendMail(orderMail(html: null)->trackingOpens(true))->tracksOpens)->toBeFalse();

        $this->relay->seedTracking($domain->id, clicks: true, opens: false);
        $this->relay->assertDomainTracksOpens($domain->id, false);
        expect(fn () => $this->relay->assertDomainTracksOpens('sdm_missing'))->toThrow(AssertionFailedError::class);
    });
});

// Through Laravel's mailer

describe('the relay mail transport', function (): void {
    beforeEach(function (): void {
        config()->set('mail.default', 'relay');
        config()->set('mail.mailers.relay', ['transport' => 'relay']);
        config()->set('mail.from', ['address' => 'hello@acme.dk', 'name' => 'Acme']);
        $this->relay = Relay::fake();
    });

    it('carries a mailable\'s own tag and metadata as the mail\'s, not as headers', function (): void {
        Mail::html('<p>Thanks</p>', function (Message $message): void {
            $message->to('ann@example.com')->subject('Your order');
            $message->getHeaders()->add(new TagHeader('invoice'));
            $message->getHeaders()->add(new TagHeader('billing'));
            $message->getHeaders()->add(new MetadataHeader('invoice_id', 'inv_1'));
            $message->getHeaders()->addTextHeader('X-Order', 'o_1');
        });

        $this->relay->assertMailSent(fn (OutgoingMail $mail): bool => $mail->tags === ['invoice', 'billing']
            && $mail->metadata === ['invoice_id' => 'inv_1']
            && $mail->headers === ['X-Order' => 'o_1']);
    });

    it('sends an embedded image inline and points the HTML at the id Relay gets', function (): void {
        Mail::send([], [], function (Message $message): void {
            $cid = $message->embedData('PNG', 'logo.png', 'image/png');
            $message->to('ann@example.com')->subject('Your order')->html('<p><img src="'.$cid.'"></p>')->attachData('paid', 'receipt.txt', ['mime' => 'text/plain']);
        });

        $this->relay->assertMailSent(function (OutgoingMail $mail): bool {
            [$logo, $receipt] = $mail->attachments;

            return $logo->contentId === 'logo.png'
                && $logo->type === 'image/png'
                && $mail->html === '<p><img src="cid:logo.png"></p>'
                && $receipt->contentId === null
                && $receipt->name === 'receipt.txt';
        });
    });

    it('takes open tracking and a send time from control headers and never passes them on', function (): void {
        Mail::html('<p>Thanks</p>', function (Message $message): void {
            $message->to('ann@example.com')->subject('Your order');
            $message->getHeaders()->addTextHeader('X-Relay-Track-Opens', 'true')->addTextHeader('X-Relay-Send-At', '2026-12-01T08:00:00Z');
        });

        $this->relay->assertMailSent(fn (OutgoingMail $mail): bool => $mail->trackOpens === true
            && $mail->trackClicks === null
            && $mail->sendAt?->format('Y-m-d H:i') === '2026-12-01 08:00'
            && $mail->headers === []);
    });

    it('refuses a control header it cannot read, and sends nothing', function (string $header, string $value): void {
        expect(fn () => Mail::raw('Thanks', function (Message $message) use ($header, $value): void {
            $message->to('ann@example.com')->subject('Hi');
            $message->getHeaders()->addTextHeader($header, $value);
        }))->toThrow(TransportException::class);

        $this->relay->assertNoMailSent();
    })->with([
        'opens' => ['X-Relay-Track-Opens', 'maybe'],
        'send at' => ['X-Relay-Send-At', 'next tuesday-ish at noon o\'clock'],
    ]);
});
