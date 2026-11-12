<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\Endpoint;
use Cbox\Relay\Client\Data\EndpointChanges;
use Cbox\Relay\Client\Data\Envelope;
use Cbox\Relay\Client\Data\NewEndpoint;
use Cbox\Relay\Client\Data\PingResult;
use Cbox\Relay\Client\Data\SignatureScheme;
use DateTimeInterface;

/** A scope's webhook endpoints: where its events are delivered. Reached through `Relay::endpoints()`. */
class Endpoints
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingScope $scope = new ActingScope,
    ) {}

    /**
     * Register an endpoint. `$endpoint->secret` on the answer is the signing secret,
     * given this once: hand it to whoever owns the URL. The URL must be a public https
     * address. `$eventTypes` takes a type, a group (`order.*`) or `*`.
     *
     * @param  list<string>  $eventTypes
     */
    public function register(string $url, array $eventTypes, ?string $description = null, ?Envelope $envelope = null, ?SignatureScheme $signatureScheme = null, ?string $idempotencyKey = null): Endpoint
    {
        return $this->transport->createEndpoint(new NewEndpoint($url, $eventTypes, $description, $envelope, $signatureScheme), $idempotencyKey, $this->scope->required());
    }

    /**
     * @return list<Endpoint>
     */
    public function list(): array
    {
        return $this->transport->listEndpoints($this->scope->required());
    }

    /** The endpoint, or null when there is none with that id in the scope. */
    public function find(string $id): ?Endpoint
    {
        return $this->transport->findEndpoint($id, $this->scope->required());
    }

    /**
     * Change what is given; the rest stays. `$clearDescription` removes the description.
     * The signature scheme cannot be changed.
     *
     * @param  list<string>|null  $eventTypes
     */
    public function update(string $id, ?string $url = null, ?array $eventTypes = null, ?string $description = null, bool $clearDescription = false, ?Envelope $envelope = null): Endpoint
    {
        return $this->transport->updateEndpoint($id, new EndpointChanges($url, $eventTypes, $description, $clearDescription, $envelope), $this->scope->required());
    }

    public function delete(string $id): void
    {
        $this->transport->deleteEndpoint($id, $this->scope->required());
    }

    /** Stop sending. Events are kept and can be replayed. */
    public function pause(string $id): Endpoint
    {
        return $this->transport->pauseEndpoint($id, $this->scope->required());
    }

    public function resume(string $id): Endpoint
    {
        return $this->transport->resumeEndpoint($id, $this->scope->required());
    }

    /**
     * Make a new signing secret, given on the answer this once. The old one keeps
     * working until `$endpoint->previousSecretExpiresAt`.
     */
    public function rotateSecret(string $id): Endpoint
    {
        return $this->transport->rotateEndpointSecret($id, $this->scope->required());
    }

    /** Send a test event now and see how the receiver answered. */
    public function ping(string $id): PingResult
    {
        return $this->transport->pingEndpoint($id, $this->scope->required());
    }

    /**
     * Queue the events since a moment again for this endpoint.
     *
     * @param  list<string>  $types  only these types; empty for all
     * @return int how many deliveries were queued
     */
    public function replay(string $id, DateTimeInterface $since, ?DateTimeInterface $until = null, array $types = []): int
    {
        return $this->transport->replayEndpoint($id, $since, $until, $types, $this->scope->required());
    }
}
