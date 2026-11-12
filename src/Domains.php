<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\DomainReputation;
use Cbox\Relay\Client\Data\SendingDomain;

/** The domains a project sends mail from. Reached through `Relay::domains()`. */
class Domains
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingProject $project = new ActingProject,
    ) {}

    /** Register a domain. The answer lists the DNS records to publish; it can send once the DKIM record is verified. */
    public function register(string $domain, ?string $idempotencyKey = null): SendingDomain
    {
        return $this->transport->createDomain($domain, $idempotencyKey, $this->project->required());
    }

    /**
     * @return list<SendingDomain>
     */
    public function list(): array
    {
        return $this->transport->listDomains($this->project->required());
    }

    public function find(string $id): ?SendingDomain
    {
        return $this->transport->findDomain($id, $this->project->required());
    }

    /** Look the DKIM record up now. The answer is the domain either way; its status says whether it was found. */
    public function verify(string $id): SendingDomain
    {
        return $this->transport->verifyDomain($id, $this->project->required());
    }

    /** How the mail this project sent from the domain fared lately: the numbers Relay's automatic pause looks at. */
    public function reputation(string $id): DomainReputation
    {
        return $this->transport->domainReputation($id, $this->project->required());
    }

    /** Stop sending from the domain. Mail still waiting from it is not sent. */
    public function remove(string $id): void
    {
        $this->transport->deleteDomain($id, $this->project->required());
    }
}
