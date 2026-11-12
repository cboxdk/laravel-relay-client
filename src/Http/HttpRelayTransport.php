<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Http;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\Identity;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\Scope;
use Cbox\Relay\Client\Exceptions\RelayMalformedResponse;
use Cbox\Relay\Client\Exceptions\RelayNotConfigured;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Exceptions\RelayUnavailable;
use Cbox\Relay\Client\RelayConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use stdClass;

/**
 * The Relay API over Laravel's HTTP client: bearer key, `Relay-Scope` when acting for a
 * scope, the configured timeouts, and every error answer as a typed exception. One call
 * is one request; nothing is retried here.
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
