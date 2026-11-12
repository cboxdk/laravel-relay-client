<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\EventPublisher;
use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\Direction;
use Cbox\Relay\Client\Data\Erasure;
use Cbox\Relay\Client\Data\Identity;
use Cbox\Relay\Client\Data\JudgeInput;
use Cbox\Relay\Client\Data\JudgeKind;
use Cbox\Relay\Client\Data\Judgment;
use Cbox\Relay\Client\Data\Mail;
use Cbox\Relay\Client\Data\MailAddress;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\Published;
use Cbox\Relay\Client\Data\PushMessage;
use Cbox\Relay\Client\Data\PushUrgency;
use Cbox\Relay\Client\Data\TemplateReference;
use Cbox\Relay\Client\Exceptions\RelayProjectRequired;
use Cbox\Relay\Client\Jobs\PublishEvent;
use Cbox\Relay\Client\Publishing\ImmediateEventPublisher;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The Cbox Relay client: a thin, typed front for the {@see RelayTransport} contract.
 * An instance is immutable; `forProject()` and `withoutProject()` return a new one.
 *
 * Most of Relay acts for one project. Those calls throw {@see RelayProjectRequired} before
 * anything is sent when no project is named (`forProject()` or `relay.project`), unless the
 * key is a project key (`relay.project_key`). `whoami()`, `projects()`, `deliveries()` and
 * `inbound()` work without one.
 */
class Relay
{
    private ActingProject $acting;

    private readonly EventPublisher $publisher;

    /**
     * @param  EventPublisher|null  $publisher  how `publish()` hands events over; null publishes at once through the transport
     * @param  bool  $projectKey  the API key is a project key, so calls that need a project go out without `Relay-Project`
     */
    public function __construct(
        private readonly RelayTransport $transport,
        ?string $project = null,
        ?EventPublisher $publisher = null,
        bool $projectKey = false,
    ) {
        $this->acting = new ActingProject($project, $projectKey);
        $this->publisher = $publisher ?? new ImmediateEventPublisher($transport);
    }

    /** Whose key this is, on which environment, and what it may do. */
    public function whoami(): Identity
    {
        return $this->transport->whoami($this->acting->ref);
    }

    public function projects(): Projects
    {
        return new Projects($this->transport, $this->acting->ref);
    }

    /**
     * The delivery log, one page at a time: every attempt on every channel.
     *
     * @return Page<Delivery>
     */
    public function deliveries(?DeliveryFilter $filter = null, ?int $limit = null, ?string $cursor = null): Page
    {
        return $this->transport->listDeliveries($filter ?? new DeliveryFilter, $limit, $cursor, $this->acting->ref);
    }

    // Webhooks out

    public function eventTypes(): EventTypes
    {
        return new EventTypes($this->transport, $this->acting);
    }

    public function endpoints(): Endpoints
    {
        return new Endpoints($this->transport, $this->acting);
    }

    public function events(): Events
    {
        return new Events($this->transport, $this->acting);
    }

    /**
     * Publish an event to the project's webhook endpoints, without waiting for Relay: by
     * default a queued job makes the call after the surrounding database transaction
     * commits, and retries it. With `relay.publish.sync`, and under `Relay::fake()`, the
     * call is made before this returns.
     *
     * An event without an `$idempotencyKey` gets one here, so the job's retries cannot
     * publish it twice, and one without `$occurredAt` is stamped with the present moment. Pass your own (`"order.paid:{$order->id}"`) when the same fact
     * may be published from more than one place.
     *
     * `$subject` is your own id for the person the event is about; see {@see eraseSubject()}.
     *
     * @param  array<string, mixed>  $payload
     * @return string the idempotency key the event was handed over with
     */
    public function publish(string $type, array $payload = [], ?string $idempotencyKey = null, ?DateTimeInterface $occurredAt = null, ?string $subject = null): string
    {
        $key = $idempotencyKey ?? PublishEvent::newIdempotencyKey();

        // Stamped here: the job may run much later, and that is not when the event occurred.
        $this->publisher->publish(new NewEvent($type, $payload, $key, $occurredAt ?? Carbon::now(), $subject), $this->acting->required());

        return $key;
    }

    /**
     * Publish an event with one HTTP call, now, and answer what Relay said. A failure is
     * the caller's to handle: nothing is retried.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publishNow(string $type, array $payload = [], ?string $idempotencyKey = null, ?DateTimeInterface $occurredAt = null, ?string $subject = null): Published
    {
        return $this->transport->publishEvent(new NewEvent($type, $payload, $idempotencyKey, $occurredAt, $subject), $this->acting->required());
    }

    /**
     * Publish up to 100 events with one HTTP call, now. All are stored or none.
     *
     * @param  list<NewEvent>  $events
     * @return list<Published> in the order the events were given
     */
    public function publishBatch(array $events): array
    {
        return $this->transport->publishEvents($events, $this->acting->required());
    }

    /**
     * Forget one person inside the project: remove the events that were published with
     * this `$subject`, and their deliveries and attempts. Events published without a
     * subject are not found. Safe to repeat: the second call removes nothing.
     */
    public function eraseSubject(string $subject): Erasure
    {
        return $this->transport->eraseSubject($subject, $this->acting->required());
    }

    // Webhooks in

    public function sources(): Sources
    {
        return new Sources($this->transport, $this->acting);
    }

    // The judge

    /**
     * Score a mail or a template without sending it. At least one of `$html` and `$text`.
     * `$judgment->created` is false when exactly this content was judged before.
     *
     * @param  array<string, string>  $headers
     */
    public function judge(
        ?string $html = null,
        ?string $text = null,
        ?string $subject = null,
        MailAddress|string|null $from = null,
        ?TemplateReference $template = null,
        ?JudgeKind $kind = null,
        ?Direction $direction = null,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): Judgment {
        return $this->transport->judge(
            new JudgeInput($html, $text, $subject, is_string($from) ? new MailAddress($from) : $from, $template, $kind, $direction, $headers),
            $idempotencyKey,
            $this->acting->required(),
        );
    }

    /** A judgment that was given; null when there is none with that id. */
    public function judgment(string $id): ?Judgment
    {
        return $this->transport->findJudgment($id, $this->acting->required());
    }

    // Inbound mail

    public function inboundRoutes(): InboundRoutes
    {
        return new InboundRoutes($this->transport, $this->acting);
    }

    public function inbound(): Inbound
    {
        return new Inbound($this->transport, $this->acting);
    }

    // Web Push

    /** The project's VAPID public key: give it to the browser as `applicationServerKey`. */
    public function pushKey(): string
    {
        return $this->transport->pushKey($this->acting->required());
    }

    public function pushSubscriptions(): PushSubscriptions
    {
        return new PushSubscriptions($this->transport, $this->acting);
    }

    /**
     * Send a message to one browser. Relay stores and queues it; `pushMessage()` and the
     * delivery log say what became of it.
     *
     * @param  string  $subscription  a `PushSubscription::$id` (`psb_…`)
     * @param  string|array<string, mixed>  $payload  what the service worker receives, at most 3000 bytes
     * @param  int|null  $ttl  seconds the push service keeps it for an offline device
     */
    public function push(string $subscription, string|array $payload, ?int $ttl = null, ?PushUrgency $urgency = null, ?string $topic = null, ?string $idempotencyKey = null): PushMessage
    {
        return $this->transport->sendPush($subscription, $payload, $ttl, $urgency, $topic, $idempotencyKey, $this->acting->required());
    }

    /** What became of a push message; null when there is none with that id. */
    public function pushMessage(string $id): ?PushMessage
    {
        return $this->transport->findPush($id, $this->acting->required());
    }

    // Outbound mail

    public function domains(): Domains
    {
        return new Domains($this->transport, $this->acting);
    }

    public function suppressions(): Suppressions
    {
        return new Suppressions($this->transport, $this->acting);
    }

    /**
     * Hand a mail to Relay. `$mail->status` on the answer says what happens next:
     * `queued`, `blocked` (every recipient is suppressed) or `quarantined`.
     */
    public function sendMail(OutgoingMail $mail, ?string $idempotencyKey = null): Mail
    {
        return $this->transport->sendMail($mail, $idempotencyKey, $this->acting->required());
    }

    /** What became of a mail and of each recipient; null when there is none with that id. */
    public function mail(string $id): ?Mail
    {
        return $this->transport->findMail($id, $this->acting->required());
    }

    /**
     * Send a mail the filter held (`quarantined`) after all. It is queued as if it had
     * not been held; recipients suppressed since are left out. A mail that is not held
     * throws `RelayConflict` with the type `mail_not_held`.
     */
    public function releaseMail(string $id): Mail
    {
        return $this->transport->releaseMail($id, $this->acting->required());
    }

    /**
     * Give up a mail the filter held: it is never sent, becomes `blocked`, and its
     * content is removed. A mail that is not held throws `RelayConflict` with the type
     * `mail_not_held`.
     */
    public function discardMail(string $id): Mail
    {
        return $this->transport->discardMail($id, $this->acting->required());
    }

    // Project

    /**
     * A client whose every call acts for that project (its external ref or `prj_…` id) by
     * sending `Relay-Project`. For organization keys; a project key is already locked to its
     * project and Relay refuses it naming another.
     */
    public function forProject(string $ref): static
    {
        $relay = clone $this;
        $relay->acting = $this->acting->with($ref);

        return $relay;
    }

    /** A client that acts as the key itself, whatever `relay.project` says. */
    public function withoutProject(): static
    {
        $relay = clone $this;
        $relay->acting = $this->acting->with(null);

        return $relay;
    }

    /** The project this client acts for; null when it acts as the key itself. */
    public function project(): ?string
    {
        return $this->acting->ref;
    }

    public function transport(): RelayTransport
    {
        return $this->transport;
    }
}
