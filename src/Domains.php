<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\SendingDomain;

/** The domains a scope sends mail from. Reached through `Relay::domains()`. */
class Domains
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingScope $scope = new ActingScope,
    ) {}

    /** Register a domain. The answer lists the DNS records to publish; it can send once the DKIM record is verified. */
    public function register(string $domain, ?string $idempotencyKey = null): SendingDomain
    {
        return $this->transport->createDomain($domain, $idempotencyKey, $this->scope->required());
    }

    /**
     * @return list<SendingDomain>
     */
    public function list(): array
    {
        return $this->transport->listDomains($this->scope->required());
    }

    public function find(string $id): ?SendingDomain
    {
        return $this->transport->findDomain($id, $this->scope->required());
    }

    /** Look the DKIM record up now. The answer is the domain either way; its status says whether it was found. */
    public function verify(string $id): SendingDomain
    {
        return $this->transport->verifyDomain($id, $this->scope->required());
    }

    /** Stop sending from the domain. Mail still waiting from it is not sent. */
    public function remove(string $id): void
    {
        $this->transport->deleteDomain($id, $this->scope->required());
    }
}
