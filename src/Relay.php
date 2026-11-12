<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\Identity;
use Cbox\Relay\Client\Data\Page;

/**
 * The Cbox Relay client: a thin, typed front for the {@see RelayTransport} contract.
 * An instance is immutable; `forScope()` and `withoutScope()` return a new one.
 */
class Relay
{
    private ?string $scope;

    public function __construct(
        private readonly RelayTransport $transport,
        ?string $scope = null,
    ) {
        $this->scope = $scope === '' ? null : $scope;
    }

    /** Whose key this is, on which environment, and what it may do. */
    public function whoami(): Identity
    {
        return $this->transport->whoami($this->scope);
    }

    public function scopes(): Scopes
    {
        return new Scopes($this->transport, $this->scope);
    }

    /**
     * The delivery log, one page at a time.
     *
     * @return Page<Delivery>
     */
    public function deliveries(?DeliveryFilter $filter = null, ?int $limit = null, ?string $cursor = null): Page
    {
        return $this->transport->listDeliveries($filter ?? new DeliveryFilter, $limit, $cursor, $this->scope);
    }

    /**
     * A client whose every call acts for that scope (its external ref or `scp_…` id) by
     * sending `Relay-Scope`. For organization keys; a scope key is already locked to its
     * scope and Relay refuses the header from it.
     */
    public function forScope(string $ref): static
    {
        $relay = clone $this;
        $relay->scope = $ref === '' ? null : $ref;

        return $relay;
    }

    /** A client that acts as the key itself, whatever `relay.scope` says. */
    public function withoutScope(): static
    {
        $relay = clone $this;
        $relay->scope = null;

        return $relay;
    }

    /** The scope this client acts for; null when it acts as the key itself. */
    public function scope(): ?string
    {
        return $this->scope;
    }

    public function transport(): RelayTransport
    {
        return $this->transport;
    }
}
