<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\DomainReputation;
use Cbox\Relay\Client\Data\SendingDomain;
use Cbox\Relay\Client\Data\TrackingChanges;

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
        return $this->transport->createDomain($domain, $idempotencyKey, $this->project->ref);
    }

    /**
     * @return list<SendingDomain>
     */
    public function list(): array
    {
        return $this->transport->listDomains($this->project->ref);
    }

    public function find(string $id): ?SendingDomain
    {
        return $this->transport->findDomain($id, $this->project->ref);
    }

    /** Look the DKIM record up now. The answer is the domain either way; its status says whether it was found. */
    public function verify(string $id): SendingDomain
    {
        return $this->transport->verifyDomain($id, $this->project->ref);
    }

    /**
     * Turn click tracking on or off for the domain: every mail from it follows, unless a
     * mail says otherwise. With it on, the `http` and `https` links in a mail's HTML go
     * through Relay, which records the click and sends the reader on.
     */
    public function trackClicks(string $id, bool $clicks = true): SendingDomain
    {
        return $this->transport->updateDomain($id, new TrackingChanges(clicks: $clicks), $this->project->ref);
    }

    /**
     * Turn open tracking on or off for the domain: with it on, the HTML of every mail from
     * it ends with an invisible image that reports when a mail client fetches it, unless a
     * mail says otherwise. An open is a weak signal (mail clients fetch images on their
     * own, or not at all) and it is personal data: turning it on is your decision.
     */
    public function trackOpens(string $id, bool $opens = true): SendingDomain
    {
        return $this->transport->updateDomain($id, new TrackingChanges(opens: $opens), $this->project->ref);
    }

    /**
     * Change the domain's tracking: clicks and opens on or off, and the host tracked links are
     * written on. A host of the domain's own is a CNAME to Relay's tracking host; the
     * record to publish is in the answer's `$records` with the purpose `tracking`.
     */
    public function tracking(string $id, TrackingChanges $changes): SendingDomain
    {
        return $this->transport->updateDomain($id, $changes, $this->project->ref);
    }

    /** How the mail this project sent from the domain fared lately: the numbers Relay's automatic pause looks at. */
    public function reputation(string $id): DomainReputation
    {
        return $this->transport->domainReputation($id, $this->project->ref);
    }

    /** Stop sending from the domain. Mail still waiting from it is not sent. */
    public function remove(string $id): void
    {
        $this->transport->deleteDomain($id, $this->project->ref);
    }
}
