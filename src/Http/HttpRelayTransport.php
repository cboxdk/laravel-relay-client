<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Http;

use Cbox\Relay\Client\Contracts\RelayTransport;
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
use Cbox\Relay\Client\Data\Payload;
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
use Cbox\Relay\Client\Exceptions\RelayMalformedResponse;
use Cbox\Relay\Client\Exceptions\RelayNotConfigured;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\Jobs\PublishEvent;
use Cbox\Relay\Client\RelayConfig;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use SensitiveParameter;
use stdClass;

/**
 * The Relay API over Laravel's HTTP client: bearer key, `Relay-Project` when acting for a
 * project, the configured timeouts, and every error answer as a typed exception. It speaks
 * for one connection: the default, or the one it was built for by name. One call
 * is one request; nothing is retried here (the queued publish retries on its own, see
 * {@see PublishEvent}).
 */
class HttpRelayTransport implements RelayTransport
{
    public function __construct(
        private readonly RelayConfig $config,
        private readonly Factory $http,
        private readonly ?string $connection = null,
    ) {}

    public function whoami(?string $project = null): Identity
    {
        return Identity::fromArray($this->body($this->send('GET', '/v1/whoami', project: $project)));
    }

    public function putProject(string $externalRef, ?string $name = null, ?array $settings = null, ?string $idempotencyKey = null, ?string $project = null): Project
    {
        $body = [];

        if ($name !== null) {
            $body['name'] = $name;
        }

        if ($settings !== null) {
            // An empty PHP array would go out as `[]`; settings is a JSON object.
            $body['settings'] = $settings === [] ? new stdClass : $settings;
        }

        $response = $this->send('PUT', '/v1/projects/'.rawurlencode($externalRef), body: $body, project: $project, idempotencyKey: $idempotencyKey);

        return Project::fromArray($this->body($response), created: $response->status() === 201);
    }

    public function findProject(string $ref, ?string $project = null): ?Project
    {
        try {
            $response = $this->send('GET', '/v1/projects/'.rawurlencode($ref), project: $project);
        } catch (RelayNotFound) {
            return null;
        }

        return Project::fromArray($this->body($response));
    }

    public function listProjects(?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        $response = $this->send('GET', '/v1/projects', $this->paging($limit, $cursor), project: $project);

        return Page::fromArray($this->body($response), static fn (array $item): Project => Project::fromArray($item));
    }

    public function listDeliveries(DeliveryFilter $filter = new DeliveryFilter, ?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        $response = $this->send('GET', '/v1/deliveries', [...$filter->toQuery(), ...$this->paging($limit, $cursor)], project: $project);

        return Page::fromArray($this->body($response), static fn (array $item): Delivery => Delivery::fromArray($item));
    }

    public function listEventTypes(?string $project = null): array
    {
        return $this->items($this->send('GET', '/v1/event-types', project: $project), EventType::fromArray(...));
    }

    public function putEventTypes(array $types, ?string $project = null): array
    {
        $body = ['types' => array_map(static fn (EventType $type): array => $type->toArray(), $types)];

        return $this->items($this->send('PUT', '/v1/event-types', body: $body, project: $project), EventType::fromArray(...));
    }

    public function publishEvent(NewEvent $event, ?string $project = null): Published
    {
        return Published::fromArray($this->body($this->send('POST', '/v1/events', body: $event->toArray(), project: $project)));
    }

    public function publishEvents(array $events, ?string $project = null): array
    {
        $body = ['events' => array_map(static fn (NewEvent $event): array => $event->toArray(), $events)];

        return $this->items($this->send('POST', '/v1/events/batch', body: $body, project: $project), Published::fromArray(...));
    }

    public function listEvents(?string $type = null, ?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        $query = [...($type === null || $type === '' ? [] : ['type' => $type]), ...$this->paging($limit, $cursor)];

        return Page::fromArray($this->body($this->send('GET', '/v1/events', $query, project: $project)), Event::fromArray(...));
    }

    public function findEvent(string $id, ?string $project = null): ?Event
    {
        $body = $this->find('/v1/events/'.rawurlencode($id), $project);

        return $body === null ? null : Event::fromArray($body);
    }

    public function retryWebhookDelivery(string $id, ?string $project = null): DeliveryRetry
    {
        return DeliveryRetry::fromArray($this->body($this->send('POST', '/v1/webhook-deliveries/'.rawurlencode($id).'/retry', project: $project)));
    }

    public function listEndpoints(?string $project = null): array
    {
        return $this->items($this->send('GET', '/v1/endpoints', project: $project), Endpoint::fromArray(...));
    }

    public function createEndpoint(NewEndpoint $endpoint, ?string $idempotencyKey = null, ?string $project = null): Endpoint
    {
        return Endpoint::fromArray($this->body($this->send('POST', '/v1/endpoints', body: $endpoint->toArray(), project: $project, idempotencyKey: $idempotencyKey)));
    }

    public function findEndpoint(string $id, ?string $project = null): ?Endpoint
    {
        $body = $this->find('/v1/endpoints/'.rawurlencode($id), $project);

        return $body === null ? null : Endpoint::fromArray($body);
    }

    public function updateEndpoint(string $id, EndpointChanges $changes, ?string $project = null): Endpoint
    {
        return Endpoint::fromArray($this->body($this->send('PATCH', '/v1/endpoints/'.rawurlencode($id), body: $changes->toArray(), project: $project)));
    }

    public function deleteEndpoint(string $id, ?string $project = null): void
    {
        $this->send('DELETE', '/v1/endpoints/'.rawurlencode($id), project: $project);
    }

    public function pauseEndpoint(string $id, ?string $project = null): Endpoint
    {
        return $this->endpointAction($id, 'pause', $project);
    }

    public function resumeEndpoint(string $id, ?string $project = null): Endpoint
    {
        return $this->endpointAction($id, 'resume', $project);
    }

    public function rotateEndpointSecret(string $id, ?string $project = null): Endpoint
    {
        return $this->endpointAction($id, 'rotate-secret', $project);
    }

    public function pingEndpoint(string $id, ?string $project = null): PingResult
    {
        return PingResult::fromArray($this->body($this->send('POST', '/v1/endpoints/'.rawurlencode($id).'/ping', project: $project)));
    }

    public function replayEndpoint(string $id, DateTimeInterface $since, ?DateTimeInterface $until = null, array $types = [], ?string $project = null): int
    {
        $body = Payload::compact([
            'since' => Payload::iso($since),
            'until' => $until === null ? null : Payload::iso($until),
            'types' => $types === [] ? null : $types,
        ]);

        return Payload::int($this->body($this->send('POST', '/v1/endpoints/'.rawurlencode($id).'/replay', body: $body, project: $project)), 'queued');
    }

    public function eraseSubject(string $subject, ?string $project = null): Erasure
    {
        return Erasure::fromArray($this->body($this->send('POST', '/v1/erasures', body: ['subject' => $subject], project: $project)));
    }

    public function listSources(?string $project = null): array
    {
        return $this->items($this->send('GET', '/v1/sources', project: $project), Source::fromArray(...));
    }

    public function createSource(string $name, SourceScheme $scheme, #[SensitiveParameter] string $secret, ?string $idempotencyKey = null, ?string $project = null): Source
    {
        $body = ['name' => $name, 'scheme' => $scheme->value, 'secret' => $secret];

        return Source::fromArray($this->body($this->send('POST', '/v1/sources', body: $body, project: $project, idempotencyKey: $idempotencyKey)));
    }

    public function findSource(string $id, ?string $project = null): ?Source
    {
        $body = $this->find('/v1/sources/'.rawurlencode($id), $project);

        return $body === null ? null : Source::fromArray($body);
    }

    public function rotateSourceSecret(string $id, #[SensitiveParameter] string $secret, ?string $project = null): Source
    {
        return Source::fromArray($this->body($this->send('PUT', '/v1/sources/'.rawurlencode($id).'/secret', body: ['secret' => $secret], project: $project)));
    }

    public function pauseSource(string $id, ?string $project = null): Source
    {
        return Source::fromArray($this->body($this->send('POST', '/v1/sources/'.rawurlencode($id).'/pause', project: $project)));
    }

    public function resumeSource(string $id, ?string $project = null): Source
    {
        return Source::fromArray($this->body($this->send('POST', '/v1/sources/'.rawurlencode($id).'/resume', project: $project)));
    }

    public function deleteSource(string $id, ?string $project = null): void
    {
        $this->send('DELETE', '/v1/sources/'.rawurlencode($id), project: $project);
    }

    public function judge(JudgeInput $input, ?string $idempotencyKey = null, ?string $project = null): Judgment
    {
        $response = $this->send('POST', '/v1/judge', body: $input->toArray(), project: $project, idempotencyKey: $idempotencyKey);

        return Judgment::fromArray($this->body($response), created: $response->status() === 201);
    }

    public function findJudgment(string $id, ?string $project = null): ?Judgment
    {
        $body = $this->find('/v1/judgments/'.rawurlencode($id), $project);

        return $body === null ? null : Judgment::fromArray($body);
    }

    public function listInboundRoutes(?string $project = null): array
    {
        return $this->items($this->send('GET', '/v1/inbound/routes', project: $project), InboundRoute::fromArray(...));
    }

    public function createInboundRoute(InboundRouteType $type, ?string $domain = null, ?string $prefix = null, ?string $idempotencyKey = null, ?string $project = null, ?string $localPart = null, ?string $forwardFor = null): InboundRoute
    {
        $body = Payload::compact(['type' => $type->value, 'domain' => $domain, 'prefix' => $prefix, 'local_part' => $localPart, 'forward_for' => $forwardFor]);

        return InboundRoute::fromArray($this->body($this->send('POST', '/v1/inbound/routes', body: $body, project: $project, idempotencyKey: $idempotencyKey)));
    }

    public function verifyInboundRoute(string $id, ?string $project = null): InboundRoute
    {
        return InboundRoute::fromArray($this->body($this->send('POST', '/v1/inbound/routes/'.rawurlencode($id).'/verify', project: $project)));
    }

    public function deleteInboundRoute(string $id, ?string $project = null): void
    {
        $this->send('DELETE', '/v1/inbound/routes/'.rawurlencode($id), project: $project);
    }

    public function listInbound(?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        return Page::fromArray($this->body($this->send('GET', '/v1/inbound', $this->paging($limit, $cursor), project: $project)), InboundMessage::fromArray(...));
    }

    public function findInbound(string $id, ?string $project = null): ?InboundMessage
    {
        $body = $this->find('/v1/inbound/'.rawurlencode($id), $project);

        return $body === null ? null : InboundMessage::fromArray($body);
    }

    public function deleteInbound(string $id, ?string $idempotencyKey = null, ?string $project = null): void
    {
        $this->send('DELETE', '/v1/inbound/'.rawurlencode($id), project: $project, idempotencyKey: $idempotencyKey);
    }

    public function pushKey(?string $project = null): string
    {
        return Payload::string($this->body($this->send('GET', '/v1/push/key', project: $project)), 'public_key');
    }

    public function listPushSubscriptions(?string $ref = null, ?string $project = null): array
    {
        $query = $ref === null || $ref === '' ? [] : ['ref' => $ref];

        return $this->items($this->send('GET', '/v1/push/subscriptions', $query, project: $project), static fn (array $item): PushSubscription => PushSubscription::fromArray($item));
    }

    public function createPushSubscription(BrowserSubscription $subscription, ?string $ref = null, ?string $idempotencyKey = null, ?string $project = null): PushSubscription
    {
        $body = [...$subscription->toArray(), ...Payload::compact(['ref' => $ref])];
        $response = $this->send('POST', '/v1/push/subscriptions', body: $body, project: $project, idempotencyKey: $idempotencyKey);

        return PushSubscription::fromArray($this->body($response), created: $response->status() === 201);
    }

    public function deletePushSubscription(string $id, ?string $project = null): void
    {
        $this->send('DELETE', '/v1/push/subscriptions/'.rawurlencode($id), project: $project);
    }

    public function sendPush(string $subscription, string|array $payload, ?int $ttl = null, ?PushUrgency $urgency = null, ?string $topic = null, ?string $idempotencyKey = null, ?string $project = null): PushMessage
    {
        $body = Payload::compact([
            'subscription' => $subscription,
            // An empty PHP array would go out as `[]`; an object payload is a JSON object.
            'payload' => $payload === [] ? new stdClass : $payload,
            'ttl' => $ttl,
            'urgency' => $urgency?->value,
            'topic' => $topic,
        ]);

        return PushMessage::fromArray($this->body($this->send('POST', '/v1/push', body: $body, project: $project, idempotencyKey: $idempotencyKey)));
    }

    public function findPush(string $id, ?string $project = null): ?PushMessage
    {
        $body = $this->find('/v1/push/'.rawurlencode($id), $project);

        return $body === null ? null : PushMessage::fromArray($body);
    }

    public function listDomains(?string $project = null): array
    {
        return $this->items($this->send('GET', '/v1/domains', project: $project), SendingDomain::fromArray(...));
    }

    public function createDomain(string $domain, ?string $idempotencyKey = null, ?string $project = null): SendingDomain
    {
        return SendingDomain::fromArray($this->body($this->send('POST', '/v1/domains', body: ['domain' => $domain], project: $project, idempotencyKey: $idempotencyKey)));
    }

    public function findDomain(string $id, ?string $project = null): ?SendingDomain
    {
        $body = $this->find('/v1/domains/'.rawurlencode($id), $project);

        return $body === null ? null : SendingDomain::fromArray($body);
    }

    public function updateDomain(string $id, TrackingChanges $tracking, ?string $project = null): SendingDomain
    {
        return SendingDomain::fromArray($this->body($this->send('PATCH', '/v1/domains/'.rawurlencode($id), body: $tracking->toArray(), project: $project)));
    }

    public function verifyDomain(string $id, ?string $project = null): SendingDomain
    {
        return SendingDomain::fromArray($this->body($this->send('POST', '/v1/domains/'.rawurlencode($id).'/verify', project: $project)));
    }

    public function domainReputation(string $id, ?string $project = null): DomainReputation
    {
        return DomainReputation::fromArray($this->body($this->send('GET', '/v1/domains/'.rawurlencode($id).'/reputation', project: $project)));
    }

    public function deleteDomain(string $id, ?string $project = null): void
    {
        $this->send('DELETE', '/v1/domains/'.rawurlencode($id), project: $project);
    }

    public function listSuppressions(?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        return Page::fromArray($this->body($this->send('GET', '/v1/suppressions', $this->paging($limit, $cursor), project: $project)), static fn (array $item): Suppression => Suppression::fromArray($item));
    }

    public function createSuppression(string $address, ?SuppressionReason $reason = null, ?string $idempotencyKey = null, ?string $project = null): Suppression
    {
        $body = Payload::compact(['address' => $address, 'reason' => $reason?->value]);
        $response = $this->send('POST', '/v1/suppressions', body: $body, project: $project, idempotencyKey: $idempotencyKey);

        return Suppression::fromArray($this->body($response), created: $response->status() === 201);
    }

    public function deleteSuppression(string $address, ?string $project = null): void
    {
        $this->send('DELETE', '/v1/suppressions/'.rawurlencode($address), project: $project);
    }

    public function sendMail(OutgoingMail $mail, ?string $idempotencyKey = null, ?string $project = null): Mail
    {
        return Mail::fromArray($this->body($this->send('POST', '/v1/mail', body: $mail->toArray(), project: $project, idempotencyKey: $idempotencyKey)));
    }

    public function listMail(MailFilter $filter = new MailFilter, ?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        $response = $this->send('GET', '/v1/mail', [...$filter->toQuery(), ...$this->paging($limit, $cursor)], project: $project);

        return Page::fromArray($this->body($response), static fn (array $item): Mail => Mail::fromArray($item));
    }

    public function sendMailBatch(array $mails, ?string $idempotencyKey = null, ?string $project = null): array
    {
        $body = ['mails' => array_map(static fn (OutgoingMail $mail): array => $mail->toArray(), $mails)];

        return $this->items($this->send('POST', '/v1/mail/batch', body: $body, project: $project, idempotencyKey: $idempotencyKey), MailBatchResult::fromArray(...));
    }

    public function findMail(string $id, ?string $project = null): ?Mail
    {
        $body = $this->find('/v1/mail/'.rawurlencode($id), $project);

        return $body === null ? null : Mail::fromArray($body);
    }

    public function releaseMail(string $id, ?string $project = null): Mail
    {
        return Mail::fromArray($this->body($this->send('POST', '/v1/mail/'.rawurlencode($id).'/release', project: $project)));
    }

    public function discardMail(string $id, ?string $project = null): Mail
    {
        return Mail::fromArray($this->body($this->send('POST', '/v1/mail/'.rawurlencode($id).'/discard', project: $project)));
    }

    public function cancelMail(string $id, ?string $project = null): Mail
    {
        return Mail::fromArray($this->body($this->send('POST', '/v1/mail/'.rawurlencode($id).'/cancel', project: $project)));
    }

    private function endpointAction(string $id, string $action, ?string $project): Endpoint
    {
        return Endpoint::fromArray($this->body($this->send('POST', '/v1/endpoints/'.rawurlencode($id).'/'.$action, project: $project)));
    }

    /**
     * A read of one thing by id: its body, or null when Relay answers 404 for it.
     *
     * @return array<array-key, mixed>|null
     */
    private function find(string $path, ?string $project): ?array
    {
        try {
            return $this->body($this->send('GET', $path, project: $project));
        } catch (RelayNotFound $e) {
            // The project named in Relay-Project is what is missing, not the thing asked for.
            if (str_ends_with($e->problem->type ?? '', 'project_not_found')) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * The `data` array of an answer that is not paged.
     *
     * @template TItem
     *
     * @param  callable(array<array-key, mixed>): TItem  $item
     * @return list<TItem>
     */
    private function items(Response $response, callable $item): array
    {
        return array_map($item, Payload::maps($this->body($response), 'data'));
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $body  null sends no body at all
     */
    private function send(string $method, string $path, array $query = [], ?array $body = null, ?string $project = null, ?string $idempotencyKey = null): Response
    {
        $connection = $this->config->connection($this->connection);

        if (! $connection->configured()) {
            throw $this->connection === null
                ? new RelayNotConfigured
                : new RelayNotConfigured("The Relay connection '{$connection->name}' is not configured: it needs a key (and a URL, here or at the top level).");
        }

        $request = $this->http
            ->baseUrl($connection->url)
            ->withToken($connection->key)
            ->acceptJson()
            ->connectTimeout($connection->connectTimeout)
            ->timeout($connection->timeout);

        if ($project !== null && $project !== '') {
            $request->withHeader('Relay-Project', $project);
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $request->withHeader('Idempotency-Key', $idempotencyKey);
        }

        if ($body !== null) {
            // An empty body is the JSON object `{}`, not the `[]` an empty PHP array encodes to.
            $request->withBody($body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR), 'application/json');
        }

        try {
            $response = $request->send($method, $path, $query === [] ? [] : ['query' => $query]);
        } catch (ConnectionException $e) {
            // Guzzle's message names the host and the cause; the key is a header and is not in it.
            throw new RelayUnavailable('Cbox Relay could not be reached: '.$e->getMessage(), null, $e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw Problems::exception($response);
    }

    /**
     * @return array<string, string>
     */
    private function paging(?int $limit, ?string $cursor): array
    {
        return array_filter([
            'limit' => $limit === null ? null : (string) $limit,
            'cursor' => $cursor,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<array-key, mixed>
     */
    private function body(Response $response): array
    {
        $json = $response->json();

        return is_array($json)
            ? $json
            : throw new RelayMalformedResponse('Cbox Relay answered '.$response->status().' with a body that is not JSON.');
    }
}
