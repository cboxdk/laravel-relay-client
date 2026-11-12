<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\InboundRoute;
use Cbox\Relay\Client\Data\InboundRouteType;
use InvalidArgumentException;

/** The address patterns a project receives mail for. Reached through `Relay::inboundRoutes()`. */
class InboundRoutes
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingProject $project = new ActingProject,
    ) {}

    /**
     * Claim a pattern. A `Subdomain` is active at once; a `Domain` is pending until the
     * TXT record in `$route->verification` is found; a `Prefix` (which needs `$prefix`)
     * is pending until Relay's operator approves it. A pattern that is taken answers 422.
     * For one address use {@see self::registerAddress()} or {@see self::registerForwarding()}.
     */
    public function register(InboundRouteType $type, string $domain, ?string $prefix = null, ?string $idempotencyKey = null): InboundRoute
    {
        return $this->transport->createInboundRoute($type, $domain, $prefix, $idempotencyKey, $this->project->required());
    }

    /**
     * Receive on one address at a domain of your own (`support@acme.dk`). It is active
     * at once when the project already sends from that domain: its DKIM record proves the
     * domain is yours. Otherwise it is pending until the TXT record in
     * `$route->verification` is found. The domain's MX must point at Relay for mail to arrive.
     */
    public function registerAddress(string $address, ?string $idempotencyKey = null): InboundRoute
    {
        $at = strrpos($address, '@');

        if ($at === false || $at === 0 || $at === strlen($address) - 1) {
            throw new InvalidArgumentException("'{$address}' is not a mail address.");
        }

        return $this->transport->createInboundRoute(InboundRouteType::Address, substr($address, $at + 1), null, $idempotencyKey, $this->project->required(), substr($address, 0, $at));
    }

    /**
     * Get an address of Relay's that stands in for `$address` (yours). Forward your
     * address to `$route->address` from your mail system: your domain's MX stays where it
     * is, and nothing else at the domain changes. Active at once.
     */
    public function registerForwarding(string $address, ?string $idempotencyKey = null): InboundRoute
    {
        return $this->transport->createInboundRoute(InboundRouteType::Address, null, null, $idempotencyKey, $this->project->required(), null, $address);
    }

    /**
     * @return list<InboundRoute>
     */
    public function list(): array
    {
        return $this->transport->listInboundRoutes($this->project->required());
    }

    /** Look for a domain route's DNS record now. The answer is the route either way; its status says whether it was found. */
    public function verify(string $id): InboundRoute
    {
        return $this->transport->verifyInboundRoute($id, $this->project->required());
    }

    /** Stop receiving for the pattern. Mail already received is kept. */
    public function remove(string $id): void
    {
        $this->transport->deleteInboundRoute($id, $this->project->required());
    }
}
