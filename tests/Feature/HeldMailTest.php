<?php

declare(strict_types=1);

use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayProjectRequired;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Testing\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;

/**
 * @return array<string, mixed>
 */
function mailBody(string $status, ?string $reason = null): array
{
    return ['id' => 'msg_1', 'status' => $status, 'from' => 'hello@acme.dk', 'subject' => 'Hi', 'reason' => $reason, 'verdicts' => ['phishing' => 0.8],
        'recipients' => [['address' => 'ann@example.com', 'type' => 'to', 'status' => 'queued', 'detail' => null]], 'created_at' => '2026-11-12T10:00:00Z', 'sent_at' => null];
}

describe('over HTTP', function (): void {
    beforeEach(fn () => Http::preventStrayRequests());

    it('releases and discards a held mail', function (): void {
        Http::fake([
            'relay.test/v1/mail/msg_1/release' => Http::response(mailBody('queued'), 202),
            'relay.test/v1/mail/msg_1/discard' => Http::response(mailBody('blocked', 'Discarded.'), 200),
        ]);

        expect(Relay::forProject('ws_1')->releaseMail('msg_1')->status)->toBe('queued')
            ->and(Relay::forProject('ws_1')->discardMail('msg_1')->reason)->toBe('Discarded.');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://relay.test/v1/mail/msg_1/release' && $request->hasHeader('Relay-Project', 'ws_1') && $request->body() === '');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request->url() === 'https://relay.test/v1/mail/msg_1/discard');
    });

    it('throws a conflict that names mail_not_held', function (): void {
        Http::fake(['relay.test/v1/mail/msg_1/release' => Http::response(['type' => 'https://cboxrelay.com/problems/mail_not_held', 'title' => 'Conflict', 'status' => 409, 'detail' => 'The mail is not held.'], 409)]);

        try {
            Relay::forProject('ws_1')->releaseMail('msg_1');
            $this->fail('Nothing was thrown.');
        } catch (RelayConflict $e) {
            expect($e->type())->toBe('mail_not_held')->and($e->status())->toBe(409)->and($e->getMessage())->toContain('The mail is not held.');
        }
    });

    it('does not turn a missing mail into null', function (): void {
        Http::fake(['relay.test/v1/mail/msg_9/discard' => Http::response(['type' => 'https://cboxrelay.com/problems/mail_not_found', 'title' => 'Not found', 'status' => 404], 404)]);

        Relay::forProject('ws_1')->discardMail('msg_9');
    })->throws(RelayNotFound::class);
});

it('names the problem type, or nothing when there is none', function (): void {
    Http::fake(['relay.test/v1/whoami' => Http::sequence()
        ->push(['type' => 'https://cboxrelay.com/problems/project_suspended', 'title' => 'Forbidden', 'status' => 403], 403)
        ->push('<html>', 502)
        ->push(['type' => 'idempotency_in_progress', 'title' => 'Conflict', 'status' => 409], 409)]);

    $types = [];

    foreach (range(1, 3) as $ignored) {
        try {
            Relay::whoami();
        } catch (RelayException $e) {
            $types[] = $e->type();
        }
    }

    expect($types)->toBe(['project_suspended', null, 'idempotency_in_progress'])
        ->and((new RelayUnavailable)->type())->toBeNull();
});

describe('on the fake', function (): void {
    beforeEach(function (): void {
        $this->relay = Relay::fake();
        $this->relay->seedProject('ws_1');
        $this->scoped = Relay::forProject('ws_1');
        $this->mail = $this->scoped->sendMail(new OutgoingMail(new MailAddress('hello@acme.dk'), ['ann@example.com', 'bob@example.com'], 'Hi', text: 'Hi'));
    });

    it('releases a held mail, leaving out who was suppressed since', function (): void {
        $this->relay->markMail($this->mail->id, 'quarantined', 'phishing over the threshold');
        $this->relay->seedSuppression('ws_1', 'bob@example.com');

        $released = $this->scoped->releaseMail($this->mail->id);

        expect($released->status)->toBe('queued')
            ->and($released->reason)->toBeNull()
            ->and($released->recipients[0]->status)->toBe('queued')
            ->and($released->recipients[1]->status)->toBe('suppressed')
            ->and($this->scoped->mail($this->mail->id)?->status)->toBe('queued');

        $this->relay->assertMailReleased($this->mail->id);
        $this->relay->assertCalledTimes(Operation::ReleaseMail, 1);

        expect(fn () => $this->relay->assertMailDiscarded($this->mail->id))->toThrow(AssertionFailedError::class);
    });

    it('discards a held mail', function (): void {
        $this->relay->markMail($this->mail->id, 'quarantined', 'phishing over the threshold');

        $discarded = $this->scoped->discardMail($this->mail->id);

        expect($discarded->status)->toBe('blocked')
            ->and($discarded->reason)->not->toBeNull()
            ->and($discarded->accepted())->toBeFalse();

        $this->relay->assertMailDiscarded($this->mail->id);

        expect(fn () => $this->relay->assertMailReleased($this->mail->id))->toThrow(AssertionFailedError::class);
    });

    it('answers mail_not_held for a mail that is not quarantined, also the second time', function (): void {
        foreach ([fn () => $this->scoped->releaseMail($this->mail->id), fn () => $this->scoped->discardMail($this->mail->id)] as $call) {
            try {
                $call();
                $this->fail('Nothing was thrown.');
            } catch (RelayConflict $e) {
                expect($e->type())->toBe('mail_not_held');
            }
        }

        $this->relay->markMail($this->mail->id, 'quarantined');
        $this->scoped->releaseMail($this->mail->id);

        expect(fn () => $this->scoped->releaseMail($this->mail->id))->toThrow(RelayConflict::class)
            ->and(fn () => $this->scoped->discardMail($this->mail->id))->toThrow(RelayConflict::class)
            ->and(fn () => $this->relay->assertMailDiscarded($this->mail->id))->toThrow(AssertionFailedError::class);
    });

    it('answers 404 for a mail the project does not have and needs a project', function (): void {
        $this->relay->seedProject('ws_2');
        $this->relay->markMail($this->mail->id, 'quarantined');

        expect(fn () => $this->scoped->releaseMail('msg_missing'))->toThrow(RelayNotFound::class)
            ->and(fn () => Relay::forProject('ws_2')->discardMail($this->mail->id))->toThrow(RelayNotFound::class)
            ->and(fn () => Relay::releaseMail($this->mail->id))->toThrow(RelayProjectRequired::class)
            ->and(fn () => Relay::discardMail($this->mail->id))->toThrow(RelayProjectRequired::class);
    });
});
