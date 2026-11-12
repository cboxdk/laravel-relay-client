<?php

declare(strict_types=1);

use Cbox\Relay\Client\Exceptions\RelayForbidden;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotConfigured;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;
use Cbox\Relay\Client\Exceptions\RelayTooLarge;
use Cbox\Relay\Client\Exceptions\RelayUnauthorized;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;

/*
 * A real Laravel failover mailer, ['relay', 'array'], against Relay's HTTP API faked at
 * the HTTP client. What matters is whether the `array` mailer got the mail.
 */

beforeEach(function (): void {
    Http::preventStrayRequests();

    config()->set('mail.default', 'failover');
    config()->set('mail.mailers.relay', ['transport' => 'relay']);
    config()->set('mail.mailers.array', ['transport' => 'array']);
    config()->set('mail.mailers.failover', ['transport' => 'failover', 'mailers' => ['relay', 'array']]);
    config()->set('mail.from', ['address' => 'hello@acme.dk', 'name' => 'Acme']);
});

/** The `array` transport inside the failover mailer: Laravel builds its own, not the `array` mailer's. */
function fallbackTransport(): ArrayTransport
{
    $failover = Mail::mailer('failover')->getSymfonyTransport();
    $transports = (new ReflectionProperty(RoundRobinTransport::class, 'transports'))->getValue($failover);

    expect($transports)->toHaveCount(2)->and($transports[1])->toBeInstanceOf(ArrayTransport::class);

    return $transports[1];
}

function sendThroughFailover(?string $idempotencyKey = null): void
{
    Mail::mailer('failover')->raw('Thanks', function (Message $message) use ($idempotencyKey): void {
        $message->to('ann@example.com')->subject('Your order');

        if ($idempotencyKey !== null) {
            $message->getHeaders()->addTextHeader('X-Relay-Idempotency-Key', $idempotencyKey);
        }
    });
}

/** @return array<string, mixed> */
function accepted(): array
{
    return ['id' => 'msg_1', 'status' => 'queued', 'from' => 'hello@acme.dk', 'subject' => 'Your order', 'reason' => null, 'verdicts' => [], 'recipients' => [], 'created_at' => '2026-11-13T10:00:00Z', 'sent_at' => null];
}

it('falls back to the next mailer when Relay cannot take the mail', function (Closure $relay, string $cause): void {
    $relay();

    sendThroughFailover();

    $fallback = fallbackTransport();
    expect($fallback->messages())->toHaveCount(1)
        ->and($fallback->messages()->first()->getOriginalMessage()->getSubject())->toBe('Your order');
})->with([
    'not configured' => [fn () => config()->set('relay.key', null), RelayNotConfigured::class],
    'unreachable' => [fn () => Http::fake(fn () => throw new ConnectionException('Connection refused')), RelayUnavailable::class],
    'a timeout' => [fn () => Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out')), RelayUnavailable::class],
    '500' => [fn () => Http::fake(['*' => Http::response(['title' => 'Server Error'], 500)]), RelayUnavailable::class],
    '503' => [fn () => Http::fake(['*' => Http::response(['title' => 'Service Unavailable'], 503)]), RelayUnavailable::class],
    '429' => [fn () => Http::fake(['*' => Http::response(['title' => 'Too Many Requests'], 429, ['Retry-After' => '30'])]), RelayRateLimited::class],
    '429 sending limit' => [fn () => Http::fake(['*' => Http::response(['type' => 'https://relay.test/problems/sending_limited', 'title' => 'Sending limit reached', 'scope' => 'project'], 429)]), RelayRateLimited::class],
    '401' => [fn () => Http::fake(['*' => Http::response(['title' => 'Unauthenticated'], 401)]), RelayUnauthorized::class],
    '403' => [fn () => Http::fake(['*' => Http::response(['title' => 'Forbidden'], 403)]), RelayForbidden::class],
]);

it('throws a TransportException that keeps the Relay exception as its previous', function (Closure $relay, string $cause): void {
    config()->set('mail.default', 'relay');
    $relay();

    try {
        Mail::mailer('relay')->raw('Thanks', fn (Message $message) => $message->to('ann@example.com')->subject('Your order'));
        $this->fail('The send should have failed.');
    } catch (TransportException $e) {
        expect($e->getPrevious())->toBeInstanceOf($cause)
            ->and($e->getMessage())->toBe($e->getPrevious()?->getMessage())
            ->and($e->getDebug())->toContain($cause);
    }
})->with([
    'not configured' => [fn () => config()->set('relay.key', null), RelayNotConfigured::class],
    'unreachable' => [fn () => Http::fake(fn () => throw new ConnectionException('Connection refused')), RelayUnavailable::class],
    '503' => [fn () => Http::fake(['*' => Http::response(['title' => 'Service Unavailable'], 503)]), RelayUnavailable::class],
    '429' => [fn () => Http::fake(['*' => Http::response(['title' => 'Too Many Requests'], 429)]), RelayRateLimited::class],
    '401' => [fn () => Http::fake(['*' => Http::response(['title' => 'Unauthenticated'], 401)]), RelayUnauthorized::class],
    '403' => [fn () => Http::fake(['*' => Http::response(['title' => 'Forbidden'], 403)]), RelayForbidden::class],
]);

it('does not fall back when Relay refuses the mail itself, and fails loudly', function (int $status, string $exception): void {
    Http::fake(['*' => Http::response(['title' => 'Refused', 'errors' => ['to' => ['The address is suppressed.']]], $status)]);

    expect(fn () => sendThroughFailover())->toThrow($exception);

    expect(fallbackTransport()->messages())->toHaveCount(0);
})->with([
    '422' => [422, RelayInvalid::class],
    '413' => [413, RelayTooLarge::class],
]);

it('sends through Relay with the idempotency key and leaves the fallback alone when Relay takes it', function (): void {
    Http::fake(['*' => Http::response(accepted(), 202)]);

    sendThroughFailover('order-mail:o_1');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'order-mail:o_1')
        && ! array_key_exists('X-Relay-Idempotency-Key', (array) ($request['headers'] ?? [])));
    Http::assertSentCount(1);
    expect(fallbackTransport()->messages())->toHaveCount(0);
});

it('sends the idempotency key to Relay before it falls back', function (): void {
    Http::fake(['*' => Http::response(['title' => 'Service Unavailable'], 503)]);

    sendThroughFailover('order-mail:o_2');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'order-mail:o_2'));
    expect(fallbackTransport()->messages())->toHaveCount(1);
});
