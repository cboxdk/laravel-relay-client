<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\InboundRoute;
use Cbox\Relay\Client\Data\InboundRouteType;

/** The address patterns a scope receives mail for. Reached through `Relay::inboundRoutes()`. */
class InboundRoutes
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingScope $scope = new ActingScope,
    ) {}

    /**
     * Claim a pattern. A `Subdomain` is active at once; a `Domain` is pending until the
     * TXT record in `$route->verification` is found; a `Prefix` (which needs `$prefix`)
     * is pending until Relay's operator approves it. A pattern that is taken answers 422.
     */
    public function register(InboundRouteType $type, string $domain, ?string $prefix = null, ?string $idempotencyKey = null): InboundRoute
    {
        return $this->transport->createInboundRoute($type, $domain, $prefix, $idempotencyKey, $this->scope->required());
    }

    /**
     * @return list<InboundRoute>
     */
    public function list(): array
    {
        return $this->transport->listInboundRoutes($this->scope->required());
    }

    /** Look for a domain route's DNS record now. The answer is the route either way; its status says whether it was found. */
    public function verify(string $id): InboundRoute
    {
        return $this->transport->verifyInboundRoute($id, $this->scope->required());
    }

    /** Stop receiving for the pattern. Mail already received is kept. */
    public function remove(string $id): void
    {
        $this->transport->deleteInboundRoute($id, $this->scope->required());
    }
}
