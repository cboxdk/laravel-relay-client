<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Contracts;

use Cbox\Relay\Client\Data\BrowserSubscription;
use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\DeliveryRetry;
use Cbox\Relay\Client\Data\DomainReputation;
use Cbox\Relay\Client\Data\Endpoint;
use Cbox\Relay\Client\Data\EndpointChanges;
use Cbox\Relay\Client\Data\Erasure;
use Cbox\Relay\Client\Data\Event;
use Cbox\Relay\Client\Data\EventType;
use Cbox\Relay\Client\Data\Identity;
use Cbox\Relay\Client\Data\InboundMessage;
use Cbox\Relay\Client\Data\InboundRoute;
use Cbox\Relay\Client\Data\InboundRouteType;
use Cbox\Relay\Client\Data\JudgeInput;
use Cbox\Relay\Client\Data\Judgment;
use Cbox\Relay\Client\Data\Mail;
use Cbox\Relay\Client\Data\MailBatchResult;
use Cbox\Relay\Client\Data\MailFilter;
use Cbox\Relay\Client\Data\NewEndpoint;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\PingResult;
use Cbox\Relay\Client\Data\Project;
use Cbox\Relay\Client\Data\Published;
use Cbox\Relay\Client\Data\PushMessage;
use Cbox\Relay\Client\Data\PushSubscription;
use Cbox\Relay\Client\Data\PushUrgency;
use Cbox\Relay\Client\Data\SendingDomain;
use Cbox\Relay\Client\Data\Source;
use Cbox\Relay\Client\Data\SourceScheme;
use Cbox\Relay\Client\Data\Suppression;
use Cbox\Relay\Client\Data\SuppressionReason;
use Cbox\Relay\Client\Data\TrackingChanges;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Relay;
use DateTimeInterface;
use SensitiveParameter;

/**
 * The Relay API, one method per endpoint. Everything else in this package (the
 * {@see Relay} manager, the facade, the queued publish, the mail transport, the
 * notification channel) is a convenience over this contract; bind your own
 * implementation to decorate, record or replace the transport.
 *
 * `$project` on every method is the project the call acts for (an external ref or a `prj_…`
 * id), sent as the `Relay-Project` header; null acts as the key itself. Most of the API
 * only makes sense for one project: with an organization key and no `$project` Relay answers
 * those calls 422. The {@see Relay} manager refuses them before anything is sent; this
 * contract passes on what it is given.
 *
 * Every method throws a {@see RelayException} when Relay refuses or cannot be reached.
 * The `find…` methods answer null for a 404.
 */
interface RelayTransport
{
    /** `GET /v1/whoami` */
    public function whoami(?string $project = null): Identity;

    /**
     * `PUT /v1/projects/{external_ref}`: create the project or update the one that exists.
     * A null `$name` or `$settings` is left out of the request, so Relay keeps what it has.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public function putProject(string $externalRef, ?string $name = null, ?array $settings = null, ?string $idempotencyKey = null, ?string $project = null): Project;

    /** `GET /v1/projects/{ref}`; null when Relay answers 404. */
    public function findProject(string $ref, ?string $project = null): ?Project;

    /**
     * `GET /v1/projects`
     *
     * @return Page<Project>
     */
    public function listProjects(?int $limit = null, ?string $cursor = null, ?string $project = null): Page;

    /**
     * `GET /v1/deliveries`
     *
     * @return Page<Delivery>
     */
    public function listDeliveries(DeliveryFilter $filter = new DeliveryFilter, ?int $limit = null, ?string $cursor = null, ?string $project = null): Page;

    // Webhooks out

    /**
     * `GET /v1/event-types`
     *
     * @return list<EventType>
     */
    public function listEventTypes(?string $project = null): array;

    /**
     * `PUT /v1/event-types`: the project's types become exactly these.
     *
     * @param  list<EventType>  $types
     * @return list<EventType>
     */
    public function putEventTypes(array $types, ?string $project = null): array;

    /** `POST /v1/events`. The event's own `idempotencyKey` travels in the body. */
    public function publishEvent(NewEvent $event, ?string $project = null): Published;

    /**
     * `POST /v1/events/batch`: up to 100 events, all stored or none.
     *
     * @param  list<NewEvent>  $events
     * @return list<Published> in the order the events were given
     */
    public function publishEvents(array $events, ?string $project = null): array;

    /**
     * `GET /v1/events`: summaries, newest first.
     *
     * @return Page<Event>
     */
    public function listEvents(?string $type = null, ?int $limit = null, ?string $cursor = null, ?string $project = null): Page;

    /** `GET /v1/events/{id}`, with payload and deliveries; null when Relay answers 404. */
    public function findEvent(string $id, ?string $project = null): ?Event;

    /** `POST /v1/webhook-deliveries/{id}/retry` */
    public function retryWebhookDelivery(string $id, ?string $project = null): DeliveryRetry;

    /**
     * `GET /v1/endpoints`
     *
     * @return list<Endpoint>
     */
    public function listEndpoints(?string $project = null): array;

    /** `POST /v1/endpoints`. The answer carries the signing secret, this once. */
    public function createEndpoint(NewEndpoint $endpoint, ?string $idempotencyKey = null, ?string $project = null): Endpoint;

    /** `GET /v1/endpoints/{id}`; null when Relay answers 404. */
    public function findEndpoint(string $id, ?string $project = null): ?Endpoint;

    /** `PATCH /v1/endpoints/{id}` */
    public function updateEndpoint(string $id, EndpointChanges $changes, ?string $project = null): Endpoint;

    /** `DELETE /v1/endpoints/{id}` */
    public function deleteEndpoint(string $id, ?string $project = null): void;

    /** `POST /v1/endpoints/{id}/pause` */
    public function pauseEndpoint(string $id, ?string $project = null): Endpoint;

    /** `POST /v1/endpoints/{id}/resume` */
    public function resumeEndpoint(string $id, ?string $project = null): Endpoint;

    /** `POST /v1/endpoints/{id}/rotate-secret`. The answer carries the new secret, this once. */
    public function rotateEndpointSecret(string $id, ?string $project = null): Endpoint;

    /** `POST /v1/endpoints/{id}/ping` */
    public function pingEndpoint(string $id, ?string $project = null): PingResult;

    /**
     * `POST /v1/endpoints/{id}/replay`: queue the events since a moment again.
     *
     * @param  list<string>  $types  only these types; empty for all
     * @return int how many deliveries were queued
     */
    public function replayEndpoint(string $id, DateTimeInterface $since, ?DateTimeInterface $until = null, array $types = [], ?string $project = null): int;

    /** `POST /v1/erasures`: remove the events published with this subject, with their deliveries and attempts. */
    public function eraseSubject(string $subject, ?string $project = null): Erasure;

    // Webhooks in

    /**
     * `GET /v1/sources`
     *
     * @return list<Source>
     */
    public function listSources(?string $project = null): array;

    /** `POST /v1/sources`. The secret is the provider's and is never returned. */
    public function createSource(string $name, SourceScheme $scheme, #[SensitiveParameter] string $secret, ?string $idempotencyKey = null, ?string $project = null): Source;

    /** `GET /v1/sources/{id}`; null when Relay answers 404. */
    public function findSource(string $id, ?string $project = null): ?Source;

    /** `PUT /v1/sources/{id}/secret` */
    public function rotateSourceSecret(string $id, #[SensitiveParameter] string $secret, ?string $project = null): Source;

    /** `POST /v1/sources/{id}/pause` */
    public function pauseSource(string $id, ?string $project = null): Source;

    /** `POST /v1/sources/{id}/resume` */
    public function resumeSource(string $id, ?string $project = null): Source;

    /** `DELETE /v1/sources/{id}` */
    public function deleteSource(string $id, ?string $project = null): void;

    // The judge

    /** `POST /v1/judge` (201 → `Judgment::$created` true, 200 → false) */
    public function judge(JudgeInput $input, ?string $idempotencyKey = null, ?string $project = null): Judgment;

    /** `GET /v1/judgments/{id}`; null when Relay answers 404. */
    public function findJudgment(string $id, ?string $project = null): ?Judgment;

    // Inbound mail

    /**
     * `GET /v1/inbound/routes`
     *
     * @return list<InboundRoute>
     */
    public function listInboundRoutes(?string $project = null): array;

    /**
     * `POST /v1/inbound/routes`. `$localPart` is for the type `address`; with `$forwardFor`
     * and no `$domain`, Relay makes an address of its own that stands in for that one.
     */
    public function createInboundRoute(InboundRouteType $type, ?string $domain = null, ?string $prefix = null, ?string $idempotencyKey = null, ?string $project = null, ?string $localPart = null, ?string $forwardFor = null): InboundRoute;

    /** `POST /v1/inbound/routes/{id}/verify`: the route as it is now, verified or not. */
    public function verifyInboundRoute(string $id, ?string $project = null): InboundRoute;

    /** `DELETE /v1/inbound/routes/{id}` */
    public function deleteInboundRoute(string $id, ?string $project = null): void;

    /**
     * `GET /v1/inbound`: summaries, newest first.
     *
     * @return Page<InboundMessage>
     */
    public function listInbound(?int $limit = null, ?string $cursor = null, ?string $project = null): Page;

    /** `GET /v1/inbound/{id}`, with content and attachment links; null when Relay answers 404. */
    public function findInbound(string $id, ?string $project = null): ?InboundMessage;

    /** `DELETE /v1/inbound/{id}`: erases the mail and everything kept of it. Cannot be undone. */
    public function deleteInbound(string $id, ?string $idempotencyKey = null, ?string $project = null): void;

    // Web Push

    /** `GET /v1/push/key`: the project's VAPID public key. */
    public function pushKey(?string $project = null): string;

    /**
     * `GET /v1/push/subscriptions`: the newest 500, or only those with `$ref`.
     *
     * @return list<PushSubscription>
     */
    public function listPushSubscriptions(?string $ref = null, ?string $project = null): array;

    /** `POST /v1/push/subscriptions` (201 → `PushSubscription::$created` true, 200 → false) */
    public function createPushSubscription(BrowserSubscription $subscription, ?string $ref = null, ?string $idempotencyKey = null, ?string $project = null): PushSubscription;

    /** `DELETE /v1/push/subscriptions/{id}` */
    public function deletePushSubscription(string $id, ?string $project = null): void;

    /**
     * `POST /v1/push`: store and queue one message for one subscription.
     *
     * @param  string|array<string, mixed>  $payload  what the service worker receives; an array is sent as a JSON object
     */
    public function sendPush(string $subscription, string|array $payload, ?int $ttl = null, ?PushUrgency $urgency = null, ?string $topic = null, ?string $idempotencyKey = null, ?string $project = null): PushMessage;

    /** `GET /v1/push/{id}`; null when Relay answers 404. */
    public function findPush(string $id, ?string $project = null): ?PushMessage;

    // Outbound mail

    /**
     * `GET /v1/domains`
     *
     * @return list<SendingDomain>
     */
    public function listDomains(?string $project = null): array;

    /** `POST /v1/domains` */
    public function createDomain(string $domain, ?string $idempotencyKey = null, ?string $project = null): SendingDomain;

    /** `GET /v1/domains/{id}`; null when Relay answers 404. */
    public function findDomain(string $id, ?string $project = null): ?SendingDomain;

    /** `PATCH /v1/domains/{id}`: the domain with its click tracking changed. */
    public function updateDomain(string $id, TrackingChanges $tracking, ?string $project = null): SendingDomain;

    /** `POST /v1/domains/{id}/verify`: the domain as it is now, verified or not. */
    public function verifyDomain(string $id, ?string $project = null): SendingDomain;

    /** `GET /v1/domains/{id}/reputation` */
    public function domainReputation(string $id, ?string $project = null): DomainReputation;

    /** `DELETE /v1/domains/{id}` */
    public function deleteDomain(string $id, ?string $project = null): void;

    /**
     * `GET /v1/suppressions`
     *
     * @return Page<Suppression>
     */
    public function listSuppressions(?int $limit = null, ?string $cursor = null, ?string $project = null): Page;

    /** `POST /v1/suppressions` (201 → `Suppression::$created` true, 200 → false) */
    public function createSuppression(string $address, ?SuppressionReason $reason = null, ?string $idempotencyKey = null, ?string $project = null): Suppression;

    /** `DELETE /v1/suppressions/{address}` */
    public function deleteSuppression(string $address, ?string $project = null): void;

    /** `POST /v1/mail`: 202 when the mail is stored; `Mail::$status` says what happens next. */
    public function sendMail(OutgoingMail $mail, ?string $idempotencyKey = null, ?string $project = null): Mail;

    /**
     * `GET /v1/mail`: the project's mail, newest first.
     *
     * @return Page<Mail>
     */
    public function listMail(MailFilter $filter = new MailFilter, ?int $limit = null, ?string $cursor = null, ?string $project = null): Page;

    /**
     * `POST /v1/mail/batch`: up to 100 mails, each judged and stored on its own. One
     * result per mail, in the order given. An `Idempotency-Key` covers the whole call.
     *
     * @param  list<OutgoingMail>  $mails
     * @return list<MailBatchResult>
     */
    public function sendMailBatch(array $mails, ?string $idempotencyKey = null, ?string $project = null): array;

    /** `GET /v1/mail/{id}`; null when Relay answers 404. */
    public function findMail(string $id, ?string $project = null): ?Mail;

    /** `POST /v1/mail/{id}/release`: queue a mail the filter held. 409 `mail_not_held` when it is not quarantined. */
    public function releaseMail(string $id, ?string $project = null): Mail;

    /** `POST /v1/mail/{id}/discard`: never send a mail the filter held and remove its content. 409 `mail_not_held` when it is not quarantined. */
    public function discardMail(string $id, ?string $project = null): Mail;

    /** `POST /v1/mail/{id}/cancel`: take back a scheduled mail and remove its content. 409 `mail_not_scheduled` when it no longer waits. */
    public function cancelMail(string $id, ?string $project = null): Mail;
}
