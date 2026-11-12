<?php

declare(strict_types=1);

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\InboundRoute;
use Cbox\Relay\Client\Data\InboundRouteType;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\TrackingChanges;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Facades\Relay;
use Cbox\Relay\Client\Testing\Operation;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Mailer\Exception\TransportException;

/*
| Click tracking and single receiving addresses: over HTTP as Relay's OpenAPI document
| describes them, in the fake, and through Laravel's mailer.
*/

const WHEN = '2026-11-12T10:00:00Z';

/**
 * @param  array<string, mixed>  $body
 */
function relaySent(string $method, string $path, array $body): void
{
    Http::assertSent(fn (Request $request): bool => $request->method() === $method
        && $request->url() === 'https://relay.test'.$path
        && $request->header('Relay-Project') === ['ws_1']
        && json_decode($request->body(), true) === $body);
}

function tracked(?bool $clicks = null): OutgoingMail
{
    return new OutgoingMail(new MailAddress('hello@acme.dk', 'Acme'), ['ann@example.com'], 'Your order', html: '<a href="https://acme.dk/orders/1">Your order</a>', trackClicks: $clicks);
}

beforeEach(fn () => Http::preventStrayRequests());

// Over HTTP

it('changes a domain\'s click tracking and reads it back with its record', function (): void {
    $domain = ['id' => 'sdm_1', 'domain' => 'acme.dk', 'status' => 'verified', 'tracking' => ['clicks' => false, 'host' => null], 'records' => [
        ['purpose' => 'dkim', 'type' => 'TXT', 'name' => 'r1._domainkey.acme.dk', 'value' => 'v=DKIM1; p=…', 'required' => true],
    ], 'verified_at' => WHEN, 'checked_at' => WHEN, 'created_at' => WHEN];
    $own = [...$domain, 'tracking' => ['clicks' => true, 'host' => 'links.acme.dk'], 'records' => [...$domain['records'], ['purpose' => 'tracking', 'type' => 'CNAME', 'name' => 'links.acme.dk', 'value' => 'links.cboxrelay.com', 'required' => false]]];
    Http::fake(['relay.test/v1/domains/sdm_1' => Http::sequence()->push($domain)->push($own)->push([...$own, 'tracking' => ['clicks' => true, 'host' => null]])->push([...$domain, 'tracking' => ['clicks' => false, 'host' => null]])]);

    $transport = app(RelayTransport::class);
    $before = $transport->findDomain('sdm_1', 'ws_1');
    $changed = $transport->updateDomain('sdm_1', new TrackingChanges(clicks: true, host: 'links.acme.dk'), 'ws_1');

    expect($before?->tracking->clicks)->toBeFalse()
        ->and($before?->tracking->host)->toBeNull()
        ->and($changed->tracking->clicks)->toBeTrue()
        ->and($changed->tracking->host)->toBe('links.acme.dk')
        ->and($changed->records[1]->purpose)->toBe('tracking')
        ->and($changed->records[1]->type)->toBe('CNAME')
        ->and($changed->records[1]->required)->toBeFalse()
        ->and(Relay::forProject('ws_1')->domains()->tracking('sdm_1', new TrackingChanges(clearHost: true))->tracking->host)->toBeNull()
        ->and(Relay::forProject('ws_1')->domains()->trackClicks('sdm_1', false)->tracking->clicks)->toBeFalse();

    // Only what is set is sent: a field left out keeps its value at Relay.
    relaySent('PATCH', '/v1/domains/sdm_1', ['tracking' => ['clicks' => true, 'host' => 'links.acme.dk']]);
    relaySent('PATCH', '/v1/domains/sdm_1', ['tracking' => ['host' => null]]);
    relaySent('PATCH', '/v1/domains/sdm_1', ['tracking' => ['clicks' => false]]);
});

it('reads a domain from a Relay that does not say anything about tracking', function (): void {
    Http::fake(['relay.test/v1/domains/sdm_1' => Http::response(['id' => 'sdm_1', 'domain' => 'acme.dk', 'status' => 'pending', 'records' => [], 'verified_at' => null, 'checked_at' => null, 'created_at' => WHEN])]);

    expect(app(RelayTransport::class)->findDomain('sdm_1', 'ws_1')?->tracking->clicks)->toBeFalse();
});

it('says per mail whether clicks are tracked, and reads the answer the mail was accepted with', function (): void {
    $mail = ['id' => 'msg_1', 'status' => 'queued', 'from' => 'hello@acme.dk', 'subject' => 'Your order', 'reason' => null, 'tracking' => ['clicks' => true], 'verdicts' => [], 'recipients' => [], 'created_at' => WHEN, 'sent_at' => null];
    Http::fake(['relay.test/v1/mail' => Http::sequence()->push($mail, 202)->push([...$mail, 'tracking' => ['clicks' => false]], 202)->push([...$mail, 'id' => 'msg_3'], 202)]);

    $transport = app(RelayTransport::class);

    expect($transport->sendMail(tracked(true), null, 'ws_1')->tracksClicks)->toBeTrue()
        ->and($transport->sendMail(tracked(false), null, 'ws_1')->tracksClicks)->toBeFalse()
        ->and($transport->sendMail(tracked(), null, 'ws_1')->tracksClicks)->toBeTrue();

    $bodies = [];
    Http::assertSent(function (Request $request) use (&$bodies): bool {
        $bodies[] = json_decode($request->body(), true)['tracking'] ?? 'left out';

        return true;
    });

    // Left out, the sending domain's setting decides: nothing is sent about it.
    expect($bodies)->toBe([['clicks' => true], ['clicks' => false], 'left out']);
});

it('claims one address at a domain, and an address of Relay\'s that stands in for one', function (): void {
    $address = ['id' => 'irt_1', 'type' => 'address', 'domain' => 'acme.dk', 'prefix' => null, 'address' => 'support@acme.dk', 'forward_for' => null, 'pattern' => 'support@acme.dk', 'status' => 'pending',
        'verification' => ['type' => 'TXT', 'name' => '_relay-challenge.acme.dk', 'value' => 'relay-verify=abc'], 'verified_at' => null, 'created_at' => WHEN];
    $forwarding = [...$address, 'id' => 'irt_2', 'domain' => 'inbound.cboxrelay.com', 'address' => 'billing-acme-dk-x7k2m9qa@inbound.cboxrelay.com', 'forward_for' => 'billing@acme.dk',
        'pattern' => 'billing-acme-dk-x7k2m9qa@inbound.cboxrelay.com', 'status' => 'active', 'verification' => null, 'verified_at' => WHEN];
    Http::fake(['relay.test/v1/inbound/routes' => Http::sequence()->push($address, 201)->push($forwarding, 201)]);

    $routes = Relay::forProject('ws_1')->inboundRoutes();
    $one = $routes->registerAddress('support@acme.dk', 'a-1');
    $stand = $routes->registerForwarding('billing@acme.dk');

    expect($one->type)->toBe(InboundRouteType::Address->value)
        ->and($one->address)->toBe('support@acme.dk')
        ->and($one->forwardFor)->toBeNull()
        ->and($one->prefix)->toBeNull()
        ->and($one->active())->toBeFalse()
        ->and($one->verification?->name)->toBe('_relay-challenge.acme.dk')
        ->and($stand->active())->toBeTrue()
        ->and($stand->address)->toBe('billing-acme-dk-x7k2m9qa@inbound.cboxrelay.com')
        ->and($stand->forwardFor)->toBe('billing@acme.dk')
        ->and(fn () => $routes->registerAddress('not an address'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $routes->registerAddress('@acme.dk'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $routes->registerAddress('support@'))->toThrow(InvalidArgumentException::class);

    relaySent('POST', '/v1/inbound/routes', ['type' => 'address', 'domain' => 'acme.dk', 'local_part' => 'support']);
    relaySent('POST', '/v1/inbound/routes', ['type' => 'address', 'forward_for' => 'billing@acme.dk']);
    Http::assertSentCount(2);
});

// In the fake

it('tracks clicks in the fake per domain, and a mail may say otherwise', function (): void {
    $relay = Relay::fake();
    $domain = $relay->seedDomain('ws_1', 'acme.dk');
    $domains = Relay::forProject('ws_1')->domains();

    expect($domain->tracking->clicks)->toBeFalse()
        ->and(Relay::forProject('ws_1')->sendMail(tracked())->tracksClicks)->toBeFalse()
        ->and(Relay::forProject('ws_1')->sendMail(tracked(true))->tracksClicks)->toBeTrue();

    $relay->assertDomainTracksClicks($domain->id, false);
    expect($domains->trackClicks($domain->id)->tracking->clicks)->toBeTrue();
    $relay->assertDomainTracksClicks($domain->id);

    $sent = Relay::forProject('ws_1')->sendMail(tracked());

    expect($sent->tracksClicks)->toBeTrue()
        ->and(Relay::forProject('ws_1')->sendMail(tracked(false))->tracksClicks)->toBeFalse()
        // What became of the mail later does not change the answer it was accepted with.
        ->and($relay->markMail($sent->id, 'sent')->findMail($sent->id, 'ws_1')?->tracksClicks)->toBeTrue();

    $relay->assertMailSent(fn (OutgoingMail $mail): bool => $mail->trackClicks === false);
    $relay->assertCalledTimes(Operation::UpdateDomain, 1);

    expect(fn () => $relay->assertDomainTracksClicks($domain->id, false))->toThrow(AssertionFailedError::class)
        ->and(fn () => $relay->assertDomainTracksClicks('sdm_missing'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $domains->trackClicks('sdm_missing'))->toThrow(RelayNotFound::class);
});

it('gives a domain a host of its own for the links, with a record to publish, in the fake', function (): void {
    $relay = Relay::fake();
    $domain = $relay->seedDomain('ws_1', 'acme.dk');
    $domains = Relay::forProject('ws_1')->domains();

    $own = $domains->tracking($domain->id, new TrackingChanges(host: 'Links.Acme.dk'));
    $record = array_values(array_filter($own->records, fn ($record): bool => $record->purpose === 'tracking'));

    expect($own->tracking->host)->toBe('links.acme.dk')
        // The host alone was changed: tracking stays as it was.
        ->and($own->tracking->clicks)->toBeFalse()
        ->and($record)->toHaveCount(1)
        ->and($record[0]->type)->toBe('CNAME')
        ->and($record[0]->name)->toBe('links.acme.dk')
        ->and($record[0]->required)->toBeFalse()
        ->and($domains->trackClicks($domain->id)->tracking->host)->toBe('links.acme.dk')
        ->and(fn () => $domains->tracking($domain->id, new TrackingChanges(host: 'not a host')))->toThrow(RelayInvalid::class);

    $shared = $domains->tracking($domain->id, new TrackingChanges(clearHost: true));

    expect($shared->tracking->host)->toBeNull()
        ->and($shared->tracking->clicks)->toBeTrue()
        ->and(array_filter($shared->records, fn ($record): bool => $record->purpose === 'tracking'))->toBe([])
        ->and($relay->seedTracking($domain->id, false, 'go.acme.dk')->tracking->host)->toBe('go.acme.dk')
        ->and($domains->find($domain->id)?->tracking->clicks)->toBeFalse()
        ->and(fn () => $relay->seedTracking('sdm_missing'))->toThrow(InvalidArgumentException::class);
});

it('says what a change to tracking sends', function (): void {
    expect((new TrackingChanges)->toArray())->toBe(['tracking' => []])
        ->and((new TrackingChanges(clicks: false))->toArray())->toBe(['tracking' => ['clicks' => false]])
        ->and((new TrackingChanges(clearHost: true))->toArray())->toBe(['tracking' => ['host' => null]])
        // A host that is named wins over clearing it.
        ->and((new TrackingChanges(host: 'links.acme.dk', clearHost: true))->toArray())->toBe(['tracking' => ['host' => 'links.acme.dk']]);
});

it('claims one address in the fake: at once at a domain the project sends from, after DNS at another, or forwarded', function (): void {
    $relay = Relay::fake();
    $relay->seedDomain('ws_1', 'acme.dk');
    $routes = Relay::forProject('ws_1')->inboundRoutes();

    $proven = $routes->registerAddress('Support@Acme.dk');
    $other = $routes->registerAddress('hello@other.dk');
    $forwarding = $routes->registerForwarding('Billing@Acme.dk');

    expect($proven->active())->toBeTrue()
        ->and($proven->address)->toBe('support@acme.dk')
        ->and($proven->pattern)->toBe('support@acme.dk')
        ->and($proven->verification)->toBeNull()
        ->and($other->status)->toBe('pending')
        ->and($other->verification?->name)->toBe('_relay-challenge.other.dk')
        ->and($forwarding->active())->toBeTrue()
        ->and($forwarding->forwardFor)->toBe('billing@acme.dk')
        ->and($forwarding->domain)->toBe('inbound.cboxrelay.com')
        ->and($forwarding->address)->toStartWith('billing-acme-dk-fake')->toEndWith('@inbound.cboxrelay.com')
        ->and($routes->list())->toHaveCount(3);

    $relay->dnsPublished(false);
    expect($routes->verify($other->id)->active())->toBeFalse();

    $relay->dnsPublished();
    $verified = $routes->verify($other->id);

    expect($verified->active())->toBeTrue()
        ->and($verified->address)->toBe('hello@other.dk')
        ->and($verified->verification)->toBeNull();

    $relay->assertInboundRouteRegistered('support@acme.dk', fn (InboundRoute $route): bool => $route->type === 'address');
    $relay->assertInboundRouteRegistered($forwarding->pattern, fn (InboundRoute $route): bool => $route->forwardFor === 'billing@acme.dk');
});

it('refuses in the fake what Relay refuses an address for', function (): void {
    Relay::fake()->seedProject('ws_1');
    $transport = app(RelayTransport::class);
    $routes = Relay::forProject('ws_1')->inboundRoutes();
    $routes->registerAddress('support@acme.dk');

    expect(fn () => $routes->registerAddress('support@acme.dk'))->toThrow(RelayInvalid::class)
        ->and(fn () => $transport->createInboundRoute(InboundRouteType::Address, 'acme.dk', project: 'ws_1'))->toThrow(RelayInvalid::class)
        ->and(fn () => $transport->createInboundRoute(InboundRouteType::Domain, null, project: 'ws_1'))->toThrow(RelayInvalid::class)
        ->and(fn () => $transport->createInboundRoute(InboundRouteType::Domain, 'acme.dk', project: 'ws_1', forwardFor: 'a@acme.dk'))->toThrow(RelayInvalid::class);
});

// Through Laravel's mailer

describe('the relay mail transport', function (): void {
    beforeEach(function (): void {
        config()->set('mail.default', 'relay');
        config()->set('mail.mailers.relay', ['transport' => 'relay']);
        config()->set('mail.from', ['address' => 'hello@acme.dk', 'name' => 'Acme']);
        config()->set('relay.project', 'ws_1');
    });

    it('takes click tracking from a control header and never passes it on', function (string $value, bool $clicks): void {
        $relay = Relay::fake();
        $relay->seedProject('ws_1');

        Mail::html('<a href="https://acme.dk">Acme</a>', function (Message $message) use ($value): void {
            $message->to('ann@example.com')->subject('Your order');
            $message->getHeaders()->addTextHeader('X-Relay-Track-Clicks', $value)->addTextHeader('X-Order', 'o_1');
        });

        $relay->assertMailSent(fn (OutgoingMail $mail): bool => $mail->trackClicks === $clicks && $mail->headers === ['X-Order' => 'o_1']);
    })->with([
        'true' => ['true', true], 'TRUE' => ['TRUE', true], '1' => ['1', true], 'yes' => ['yes', true], 'on' => ['on', true],
        'false' => ['false', false], '0' => ['0', false], 'no' => ['no', false], 'off' => [' off ', false],
    ]);

    it('leaves click tracking to the sending domain without the header', function (): void {
        $relay = Relay::fake();
        $relay->seedProject('ws_1');

        Mail::raw('Thanks', fn (Message $message) => $message->to('ann@example.com')->subject('Your order'));

        $relay->assertMailSent(fn (OutgoingMail $mail): bool => $mail->trackClicks === null);
    });

    it('refuses a header that says neither yes nor no, and sends nothing', function (): void {
        $relay = Relay::fake();
        $relay->seedProject('ws_1');

        expect(fn () => Mail::raw('Thanks', function (Message $message): void {
            $message->to('ann@example.com')->subject('Your order');
            $message->getHeaders()->addTextHeader('X-Relay-Track-Clicks', 'maybe');
        }))->toThrow(TransportException::class, 'X-Relay-Track-Clicks');

        $relay->assertNoMailSent();
    });
});
