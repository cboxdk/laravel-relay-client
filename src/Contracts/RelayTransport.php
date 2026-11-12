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
use Cbox\Relay\Client\Data\NewEndpoint;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\PingResult;
use Cbox\Relay\Client\Data\Published;
use Cbox\Relay\Client\Data\PushMessage;
use Cbox\Relay\Client\Data\PushSubscription;
use Cbox\Relay\Client\Data\PushUrgency;
use Cbox\Relay\Client\Data\Scope;
use Cbox\Relay\Client\Data\SendingDomain;
use Cbox\Relay\Client\Data\Source;
use Cbox\Relay\Client\Data\SourceScheme;
use Cbox\Relay\Client\Data\Suppression;
use Cbox\Relay\Client\Data\SuppressionReason;
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
 * `$scope` on every method is the scope the call acts for (an external ref or a `scp_…`
 * id), sent as the `Relay-Scope` header; null acts as the key itself. Most of the API
 * only makes sense for one scope: with an organization key and no `$scope` Relay answers
 * those calls 422. The {@see Relay} manager refuses them before anything is sent; this
 * contract passes on what it is given.
 *
 * Every method throws a {@see RelayException} when Relay refuses or cannot be reached.
 * The `find…` methods answer null for a 404.
 */
interface RelayTransport
{
    /** `GET /v1/whoami` */
    public function whoami(?string $scope = null): Identity;

    /**
     * `PUT /v1/scopes/{external_ref}`: create the scope or update the one that exists.
     * A null `$name` or `$settings` is left out of the request, so Relay keeps what it has.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public function putScope(string $externalRef, ?string $name = null, ?array $settings = null, ?string $idempotencyKey = null, ?string $scope = null): Scope;

    /** `GET /v1/scopes/{ref}`; null when Relay answers 404. */
    public function findScope(string $ref, ?string $scope = null): ?Scope;

    /**
     * `GET /v1/scopes`
     *
     * @return Page<Scope>
     */
    public function listScopes(?int $limit = null, ?string $cursor = null, ?string $scope = null): Page;

    /**
     * `GET /v1/deliveries`
     *
     * @return Page<Delivery>
     */
    public function listDeliveries(DeliveryFilter $filter = new DeliveryFilter, ?int $limit = null, ?string $cursor = null, ?string $scope = null): Page;

    // Webhooks out

    /**
     * `GET /v1/event-types`
     *
     * @return list<EventType>
     */
    public function listEventTypes(?string $scope = null): array;

    /**
     * `PUT /v1/event-types`: the scope's types become exactly these.
     *
     * @param  list<EventType>  $types
     * @return list<EventType>
     */
    public function putEventTypes(array $types, ?string $scope = null): array;

    /** `POST /v1/events`. The event's own `idempotencyKey` travels in the body. */
    public function publishEvent(NewEvent $event, ?string $scope = null): Published;

    /**
     * `POST /v1/events/batch`: up to 100 events, all stored or none.
     *
     * @param  list<NewEvent>  $events
     * @return list<Published> in the order the events were given
     */
    public function publishEvents(array $events, ?string $scope = null): array;

    /**
     * `GET /v1/events`: summaries, newest first.
     *
     * @return Page<Event>
     */
    public function listEvents(?string $type = null, ?int $limit = null, ?string $cursor = null, ?string $scope = null): Page;

    /** `GET /v1/events/{id}`, with payload and deliveries; null when Relay answers 404. */
    public function findEvent(string $id, ?string $scope = null): ?Event;

    /** `POST /v1/webhook-deliveries/{id}/retry` */
    public function retryWebhookDelivery(string $id, ?string $scope = null): DeliveryRetry;

    /**
     * `GET /v1/endpoints`
     *
     * @return list<Endpoint>
     */
    public function listEndpoints(?string $scope = null): array;

    /** `POST /v1/endpoints`. The answer carries the signing secret, this once. */
    public function createEndpoint(NewEndpoint $endpoint, ?string $idempotencyKey = null, ?string $scope = null): Endpoint;

    /** `GET /v1/endpoints/{id}`; null when Relay answers 404. */
    public function findEndpoint(string $id, ?string $scope = null): ?Endpoint;

    /** `PATCH /v1/endpoints/{id}` */
    public function updateEndpoint(string $id, EndpointChanges $changes, ?string $scope = null): Endpoint;

    /** `DELETE /v1/endpoints/{id}` */
    public function deleteEndpoint(string $id, ?string $scope = null): void;

    /** `POST /v1/endpoints/{id}/pause` */
    public function pauseEndpoint(string $id, ?string $scope = null): Endpoint;

    /** `POST /v1/endpoints/{id}/resume` */
    public function resumeEndpoint(string $id, ?string $scope = null): Endpoint;

    /** `POST /v1/endpoints/{id}/rotate-secret`. The answer carries the new secret, this once. */
    public function rotateEndpointSecret(string $id, ?string $scope = null): Endpoint;

    /** `POST /v1/endpoints/{id}/ping` */
    public function pingEndpoint(string $id, ?string $scope = null): PingResult;

    /**
     * `POST /v1/endpoints/{id}/replay`: queue the events since a moment again.
     *
     * @param  list<string>  $types  only these types; empty for all
     * @return int how many deliveries were queued
     */
    public function replayEndpoint(string $id, DateTimeInterface $since, ?DateTimeInterface $until = null, array $types = [], ?string $scope = null): int;

    /** `POST /v1/erasures`: remove the events published with this subject, with their deliveries and attempts. */
    public function eraseSubject(string $subject, ?string $scope = null): Erasure;

    // Webhooks in

    /**
     * `GET /v1/sources`
     *
     * @return list<Source>
     */
    public function listSources(?string $scope = null): array;

    /** `POST /v1/sources`. The secret is the provider's and is never returned. */
    public function createSource(string $name, SourceScheme $scheme, #[SensitiveParameter] string $secret, ?string $idempotencyKey = null, ?string $scope = null): Source;

    /** `GET /v1/sources/{id}`; null when Relay answers 404. */
    public function findSource(string $id, ?string $scope = null): ?Source;

    /** `PUT /v1/sources/{id}/secret` */
    public function rotateSourceSecret(string $id, #[SensitiveParameter] string $secret, ?string $scope = null): Source;

    /** `POST /v1/sources/{id}/pause` */
    public function pauseSource(string $id, ?string $scope = null): Source;

    /** `POST /v1/sources/{id}/resume` */
    public function resumeSource(string $id, ?string $scope = null): Source;

    /** `DELETE /v1/sources/{id}` */
    public function deleteSource(string $id, ?string $scope = null): void;

    // The judge

    /** `POST /v1/judge` (201 → `Judgment::$created` true, 200 → false) */
    public function judge(JudgeInput $input, ?string $idempotencyKey = null, ?string $scope = null): Judgment;

    /** `GET /v1/judgments/{id}`; null when Relay answers 404. */
    public function findJudgment(string $id, ?string $scope = null): ?Judgment;

    // Inbound mail

    /**
     * `GET /v1/inbound/routes`
     *
     * @return list<InboundRoute>
     */
    public function listInboundRoutes(?string $scope = null): array;

    /** `POST /v1/inbound/routes` */
    public function createInboundRoute(InboundRouteType $type, string $domain, ?string $prefix = null, ?string $idempotencyKey = null, ?string $scope = null): InboundRoute;

    /** `POST /v1/inbound/routes/{id}/verify`: the route as it is now, verified or not. */
    public function verifyInboundRoute(string $id, ?string $scope = null): InboundRoute;

    /** `DELETE /v1/inbound/routes/{id}` */
    public function deleteInboundRoute(string $id, ?string $scope = null): void;

    /**
     * `GET /v1/inbound`: summaries, newest first.
     *
     * @return Page<InboundMessage>
     */
    public function listInbound(?int $limit = null, ?string $cursor = null, ?string $scope = null): Page;

    /** `GET /v1/inbound/{id}`, with content and attachment links; null when Relay answers 404. */
    public function findInbound(string $id, ?string $scope = null): ?InboundMessage;

    /** `DELETE /v1/inbound/{id}`: erases the mail and everything kept of it. Cannot be undone. */
    public function deleteInbound(string $id, ?string $idempotencyKey = null, ?string $scope = null): void;

    // Web Push

    /** `GET /v1/push/key`: the scope's VAPID public key. */
    public function pushKey(?string $scope = null): string;

    /**
     * `GET /v1/push/subscriptions`: the newest 500, or only those with `$ref`.
     *
     * @return list<PushSubscription>
     */
    public function listPushSubscriptions(?string $ref = null, ?string $scope = null): array;

    /** `POST /v1/push/subscriptions` (201 → `PushSubscription::$created` true, 200 → false) */
    public function createPushSubscription(BrowserSubscription $subscription, ?string $ref = null, ?string $idempotencyKey = null, ?string $scope = null): PushSubscription;

    /** `DELETE /v1/push/subscriptions/{id}` */
    public function deletePushSubscription(string $id, ?string $scope = null): void;

    /**
     * `POST /v1/push`: store and queue one message for one subscription.
     *
     * @param  string|array<string, mixed>  $payload  what the service worker receives; an array is sent as a JSON object
     */
    public function sendPush(string $subscription, string|array $payload, ?int $ttl = null, ?PushUrgency $urgency = null, ?string $topic = null, ?string $idempotencyKey = null, ?string $scope = null): PushMessage;

    /** `GET /v1/push/{id}`; null when Relay answers 404. */
    public function findPush(string $id, ?string $scope = null): ?PushMessage;

    // Outbound mail

    /**
     * `GET /v1/domains`
     *
     * @return list<SendingDomain>
     */
    public function listDomains(?string $scope = null): array;

    /** `POST /v1/domains` */
    public function createDomain(string $domain, ?string $idempotencyKey = null, ?string $scope = null): SendingDomain;

    /** `GET /v1/domains/{id}`; null when Relay answers 404. */
    public function findDomain(string $id, ?string $scope = null): ?SendingDomain;

    /** `POST /v1/domains/{id}/verify`: the domain as it is now, verified or not. */
    public function verifyDomain(string $id, ?string $scope = null): SendingDomain;

    /** `GET /v1/domains/{id}/reputation` */
    public function domainReputation(string $id, ?string $scope = null): DomainReputation;

    /** `DELETE /v1/domains/{id}` */
    public function deleteDomain(string $id, ?string $scope = null): void;

    /**
     * `GET /v1/suppressions`
     *
     * @return Page<Suppression>
     */
    public function listSuppressions(?int $limit = null, ?string $cursor = null, ?string $scope = null): Page;

    /** `POST /v1/suppressions` (201 → `Suppression::$created` true, 200 → false) */
    public function createSuppression(string $address, ?SuppressionReason $reason = null, ?string $idempotencyKey = null, ?string $scope = null): Suppression;

    /** `DELETE /v1/suppressions/{address}` */
    public function deleteSuppression(string $address, ?string $scope = null): void;

    /** `POST /v1/mail`: 202 when the mail is stored; `Mail::$status` says what happens next. */
    public function sendMail(OutgoingMail $mail, ?string $idempotencyKey = null, ?string $scope = null): Mail;

    /** `GET /v1/mail/{id}`; null when Relay answers 404. */
    public function findMail(string $id, ?string $scope = null): ?Mail;

    /** `POST /v1/mail/{id}/release`: queue a mail the filter held. 409 `mail_not_held` when it is not quarantined. */
    public function releaseMail(string $id, ?string $scope = null): Mail;

    /** `POST /v1/mail/{id}/discard`: never send a mail the filter held and remove its content. 409 `mail_not_held` when it is not quarantined. */
    public function discardMail(string $id, ?string $scope = null): Mail;
}
