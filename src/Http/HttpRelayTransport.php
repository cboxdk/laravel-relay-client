<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Http;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\BrowserSubscription;
use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\DeliveryRetry;
use Cbox\Relay\Client\Data\Endpoint;
use Cbox\Relay\Client\Data\EndpointChanges;
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
use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Data\PingResult;
use Cbox\Relay\Client\Data\Published;
use Cbox\Relay\Client\Data\PushMessage;
use Cbox\Relay\Client\Data\PushSubscription;
use Cbox\Relay\Client\Data\PushUrgency;
use Cbox\Relay\Client\Data\Scope;
use Cbox\Relay\Client\Data\SendingDomain;
use Cbox\Relay\Client\Data\Suppression;
use Cbox\Relay\Client\Data\SuppressionReason;
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
use stdClass;

/**
 * The Relay API over Laravel's HTTP client: bearer key, `Relay-Scope` when acting for a
 * scope, the configured timeouts, and every error answer as a typed exception. One call
 * is one request; nothing is retried here (the queued publish retries on its own, see
 * {@see PublishEvent}).
 */
class HttpRelayTransport implements RelayTransport
{
    public function __construct(
        private readonly RelayConfig $config,
        private readonly Factory $http,
    ) {}

    public function whoami(?string $scope = null): Identity
    {
        return Identity::fromArray($this->body($this->send('GET', '/v1/whoami', scope: $scope)));
    }

    public function putScope(string $externalRef, ?string $name = null, ?array $settings = null, ?string $idempotencyKey = null, ?string $scope = null): Scope
    {
        $body = [];

        if ($name !== null) {
            $body['name'] = $name;
        }

        if ($settings !== null) {
            // An empty PHP array would go out as `[]`; settings is a JSON object.
            $body['settings'] = $settings === [] ? new stdClass : $settings;
        }

        $response = $this->send('PUT', '/v1/scopes/'.rawurlencode($externalRef), body: $body, scope: $scope, idempotencyKey: $idempotencyKey);

        return Scope::fromArray($this->body($response), created: $response->status() === 201);
    }

    public function findScope(string $ref, ?string $scope = null): ?Scope
    {
        try {
            $response = $this->send('GET', '/v1/scopes/'.rawurlencode($ref), scope: $scope);
        } catch (RelayNotFound) {
            return null;
        }

        return Scope::fromArray($this->body($response));
    }

    public function listScopes(?int $limit = null, ?string $cursor = null, ?string $scope = null): Page
    {
        $response = $this->send('GET', '/v1/scopes', $this->paging($limit, $cursor), scope: $scope);

        return Page::fromArray($this->body($response), static fn (array $item): Scope => Scope::fromArray($item));
    }

    public function listDeliveries(DeliveryFilter $filter = new DeliveryFilter, ?int $limit = null, ?string $cursor = null, ?string $scope = null): Page
    {
        $response = $this->send('GET', '/v1/deliveries', [...$filter->toQuery(), ...$this->paging($limit, $cursor)], scope: $scope);

        return Page::fromArray($this->body($response), static fn (array $item): Delivery => Delivery::fromArray($item));
    }

    public function listEventTypes(?string $scope = null): array
    {
        return $this->items($this->send('GET', '/v1/event-types', scope: $scope), EventType::fromArray(...));
    }

    public function putEventTypes(array $types, ?string $scope = null): array
    {
        $body = ['types' => array_map(static fn (EventType $type): array => $type->toArray(), $types)];

        return $this->items($this->send('PUT', '/v1/event-types', body: $body, scope: $scope), EventType::fromArray(...));
    }

    public function publishEvent(NewEvent $event, ?string $scope = null): Published
    {
        return Published::fromArray($this->body($this->send('POST', '/v1/events', body: $event->toArray(), scope: $scope)));
    }

    public function publishEvents(array $events, ?string $scope = null): array
    {
        $body = ['events' => array_map(static fn (NewEvent $event): array => $event->toArray(), $events)];

        return $this->items($this->send('POST', '/v1/events/batch', body: $body, scope: $scope), Published::fromArray(...));
    }

    public function listEvents(?string $type = null, ?int $limit = null, ?string $cursor = null, ?string $scope = null): Page
    {
        $query = [...($type === null || $type === '' ? [] : ['type' => $type]), ...$this->paging($limit, $cursor)];

        return Page::fromArray($this->body($this->send('GET', '/v1/events', $query, scope: $scope)), Event::fromArray(...));
    }

    public function findEvent(string $id, ?string $scope = null): ?Event
    {
        $body = $this->find('/v1/events/'.rawurlencode($id), $scope);

        return $body === null ? null : Event::fromArray($body);
    }

    public function retryWebhookDelivery(string $id, ?string $scope = null): DeliveryRetry
    {
        return DeliveryRetry::fromArray($this->body($this->send('POST', '/v1/webhook-deliveries/'.rawurlencode($id).'/retry', scope: $scope)));
    }

    public function listEndpoints(?string $scope = null): array
    {
        return $this->items($this->send('GET', '/v1/endpoints', scope: $scope), Endpoint::fromArray(...));
    }

    public function createEndpoint(NewEndpoint $endpoint, ?string $idempotencyKey = null, ?string $scope = null): Endpoint
    {
        return Endpoint::fromArray($this->body($this->send('POST', '/v1/endpoints', body: $endpoint->toArray(), scope: $scope, idempotencyKey: $idempotencyKey)));
    }

    public function findEndpoint(string $id, ?string $scope = null): ?Endpoint
    {
        $body = $this->find('/v1/endpoints/'.rawurlencode($id), $scope);

        return $body === null ? null : Endpoint::fromArray($body);
    }

    public function updateEndpoint(string $id, EndpointChanges $changes, ?string $scope = null): Endpoint
    {
        return Endpoint::fromArray($this->body($this->send('PATCH', '/v1/endpoints/'.rawurlencode($id), body: $changes->toArray(), scope: $scope)));
    }

    public function deleteEndpoint(string $id, ?string $scope = null): void
    {
        $this->send('DELETE', '/v1/endpoints/'.rawurlencode($id), scope: $scope);
    }

    public function pauseEndpoint(string $id, ?string $scope = null): Endpoint
    {
        return $this->endpointAction($id, 'pause', $scope);
    }

    public function resumeEndpoint(string $id, ?string $scope = null): Endpoint
    {
        return $this->endpointAction($id, 'resume', $scope);
    }

    public function rotateEndpointSecret(string $id, ?string $scope = null): Endpoint
    {
        return $this->endpointAction($id, 'rotate-secret', $scope);
    }

    public function pingEndpoint(string $id, ?string $scope = null): PingResult
    {
        return PingResult::fromArray($this->body($this->send('POST', '/v1/endpoints/'.rawurlencode($id).'/ping', scope: $scope)));
    }

    public function replayEndpoint(string $id, DateTimeInterface $since, ?DateTimeInterface $until = null, array $types = [], ?string $scope = null): int
    {
        $body = Payload::compact([
            'since' => Payload::iso($since),
            'until' => $until === null ? null : Payload::iso($until),
            'types' => $types === [] ? null : $types,
        ]);

        return Payload::int($this->body($this->send('POST', '/v1/endpoints/'.rawurlencode($id).'/replay', body: $body, scope: $scope)), 'queued');
    }

    public function judge(JudgeInput $input, ?string $idempotencyKey = null, ?string $scope = null): Judgment
    {
        $response = $this->send('POST', '/v1/judge', body: $input->toArray(), scope: $scope, idempotencyKey: $idempotencyKey);

        return Judgment::fromArray($this->body($response), created: $response->status() === 201);
    }

    public function findJudgment(string $id, ?string $scope = null): ?Judgment
    {
        $body = $this->find('/v1/judgments/'.rawurlencode($id), $scope);

        return $body === null ? null : Judgment::fromArray($body);
    }

    public function listInboundRoutes(?string $scope = null): array
    {
        return $this->items($this->send('GET', '/v1/inbound/routes', scope: $scope), InboundRoute::fromArray(...));
    }

    public function createInboundRoute(InboundRouteType $type, string $domain, ?string $prefix = null, ?string $idempotencyKey = null, ?string $scope = null): InboundRoute
    {
        $body = Payload::compact(['type' => $type->value, 'domain' => $domain, 'prefix' => $prefix]);

        return InboundRoute::fromArray($this->body($this->send('POST', '/v1/inbound/routes', body: $body, scope: $scope, idempotencyKey: $idempotencyKey)));
    }

    public function verifyInboundRoute(string $id, ?string $scope = null): InboundRoute
    {
        return InboundRoute::fromArray($this->body($this->send('POST', '/v1/inbound/routes/'.rawurlencode($id).'/verify', scope: $scope)));
    }

    public function deleteInboundRoute(string $id, ?string $scope = null): void
    {
        $this->send('DELETE', '/v1/inbound/routes/'.rawurlencode($id), scope: $scope);
    }

    public function listInbound(?int $limit = null, ?string $cursor = null, ?string $scope = null): Page
    {
        return Page::fromArray($this->body($this->send('GET', '/v1/inbound', $this->paging($limit, $cursor), scope: $scope)), InboundMessage::fromArray(...));
    }

    public function findInbound(string $id, ?string $scope = null): ?InboundMessage
    {
        $body = $this->find('/v1/inbound/'.rawurlencode($id), $scope);

        return $body === null ? null : InboundMessage::fromArray($body);
    }

    public function deleteInbound(string $id, ?string $idempotencyKey = null, ?string $scope = null): void
    {
        $this->send('DELETE', '/v1/inbound/'.rawurlencode($id), scope: $scope, idempotencyKey: $idempotencyKey);
    }

    public function pushKey(?string $scope = null): string
    {
        return Payload::string($this->body($this->send('GET', '/v1/push/key', scope: $scope)), 'public_key');
    }

    public function listPushSubscriptions(?string $ref = null, ?string $scope = null): array
    {
        $query = $ref === null || $ref === '' ? [] : ['ref' => $ref];

        return $this->items($this->send('GET', '/v1/push/subscriptions', $query, scope: $scope), static fn (array $item): PushSubscription => PushSubscription::fromArray($item));
    }

    public function createPushSubscription(BrowserSubscription $subscription, ?string $ref = null, ?string $idempotencyKey = null, ?string $scope = null): PushSubscription
    {
        $body = [...$subscription->toArray(), ...Payload::compact(['ref' => $ref])];
        $response = $this->send('POST', '/v1/push/subscriptions', body: $body, scope: $scope, idempotencyKey: $idempotencyKey);

        return PushSubscription::fromArray($this->body($response), created: $response->status() === 201);
    }

    public function deletePushSubscription(string $id, ?string $scope = null): void
    {
        $this->send('DELETE', '/v1/push/subscriptions/'.rawurlencode($id), scope: $scope);
    }

    public function sendPush(string $subscription, string|array $payload, ?int $ttl = null, ?PushUrgency $urgency = null, ?string $topic = null, ?string $idempotencyKey = null, ?string $scope = null): PushMessage
    {
        $body = Payload::compact([
            'subscription' => $subscription,
            // An empty PHP array would go out as `[]`; an object payload is a JSON object.
            'payload' => $payload === [] ? new stdClass : $payload,
            'ttl' => $ttl,
            'urgency' => $urgency?->value,
            'topic' => $topic,
        ]);

        return PushMessage::fromArray($this->body($this->send('POST', '/v1/push', body: $body, scope: $scope, idempotencyKey: $idempotencyKey)));
    }

    public function findPush(string $id, ?string $scope = null): ?PushMessage
    {
        $body = $this->find('/v1/push/'.rawurlencode($id), $scope);

        return $body === null ? null : PushMessage::fromArray($body);
    }

    public function listDomains(?string $scope = null): array
    {
        return $this->items($this->send('GET', '/v1/domains', scope: $scope), SendingDomain::fromArray(...));
    }

    public function createDomain(string $domain, ?string $idempotencyKey = null, ?string $scope = null): SendingDomain
    {
        return SendingDomain::fromArray($this->body($this->send('POST', '/v1/domains', body: ['domain' => $domain], scope: $scope, idempotencyKey: $idempotencyKey)));
    }

    public function findDomain(string $id, ?string $scope = null): ?SendingDomain
    {
        $body = $this->find('/v1/domains/'.rawurlencode($id), $scope);

        return $body === null ? null : SendingDomain::fromArray($body);
    }

    public function verifyDomain(string $id, ?string $scope = null): SendingDomain
    {
        return SendingDomain::fromArray($this->body($this->send('POST', '/v1/domains/'.rawurlencode($id).'/verify', scope: $scope)));
    }

    public function deleteDomain(string $id, ?string $scope = null): void
    {
        $this->send('DELETE', '/v1/domains/'.rawurlencode($id), scope: $scope);
    }

    public function listSuppressions(?int $limit = null, ?string $cursor = null, ?string $scope = null): Page
    {
        return Page::fromArray($this->body($this->send('GET', '/v1/suppressions', $this->paging($limit, $cursor), scope: $scope)), static fn (array $item): Suppression => Suppression::fromArray($item));
    }

    public function createSuppression(string $address, ?SuppressionReason $reason = null, ?string $idempotencyKey = null, ?string $scope = null): Suppression
    {
        $body = Payload::compact(['address' => $address, 'reason' => $reason?->value]);
        $response = $this->send('POST', '/v1/suppressions', body: $body, scope: $scope, idempotencyKey: $idempotencyKey);

        return Suppression::fromArray($this->body($response), created: $response->status() === 201);
    }

    public function deleteSuppression(string $address, ?string $scope = null): void
    {
        $this->send('DELETE', '/v1/suppressions/'.rawurlencode($address), scope: $scope);
    }

    public function sendMail(OutgoingMail $mail, ?string $idempotencyKey = null, ?string $scope = null): Mail
    {
        return Mail::fromArray($this->body($this->send('POST', '/v1/mail', body: $mail->toArray(), scope: $scope, idempotencyKey: $idempotencyKey)));
    }

    public function findMail(string $id, ?string $scope = null): ?Mail
    {
        $body = $this->find('/v1/mail/'.rawurlencode($id), $scope);

        return $body === null ? null : Mail::fromArray($body);
    }

    private function endpointAction(string $id, string $action, ?string $scope): Endpoint
    {
        return Endpoint::fromArray($this->body($this->send('POST', '/v1/endpoints/'.rawurlencode($id).'/'.$action, scope: $scope)));
    }

    /**
     * A read of one thing by id: its body, or null when Relay answers 404 for it.
     *
     * @return array<array-key, mixed>|null
     */
    private function find(string $path, ?string $scope): ?array
    {
        try {
            return $this->body($this->send('GET', $path, scope: $scope));
        } catch (RelayNotFound $e) {
            // The scope named in Relay-Scope is what is missing, not the thing asked for.
            if (str_ends_with($e->problem->type ?? '', 'scope_not_found')) {
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
    private function send(string $method, string $path, array $query = [], ?array $body = null, ?string $scope = null, ?string $idempotencyKey = null): Response
    {
        if (! $this->config->configured()) {
            throw new RelayNotConfigured;
        }

        $request = $this->http
            ->baseUrl($this->config->url())
            ->withToken($this->config->key())
            ->acceptJson()
            ->connectTimeout($this->config->connectTimeout())
            ->timeout($this->config->timeout());

        if ($scope !== null && $scope !== '') {
            $request->withHeader('Relay-Scope', $scope);
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
