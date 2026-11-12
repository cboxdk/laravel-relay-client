<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\InboundMessage;
use Cbox\Relay\Client\Data\Page;

/**
 * Received mail. Reached through `Relay::inbound()`. These calls work with or without
 * a project: an organization key acting for none reads across its projects.
 */
class Inbound
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingProject $project = new ActingProject,
    ) {}

    /**
     * Summaries, newest first. Fetch one mail for its content.
     *
     * @return Page<InboundMessage>
     */
    public function list(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->transport->listInbound($limit, $cursor, $this->project->ref);
    }

    /** One mail with its text, HTML, headers and a short-lived link per attachment; null when there is none with that id. */
    public function find(string $id): ?InboundMessage
    {
        return $this->transport->findInbound($id, $this->project->ref);
    }

    /** Erase the mail: the row, the raw mail, the parsed content and every attachment. Cannot be undone. */
    public function erase(string $id, ?string $idempotencyKey = null): void
    {
        $this->transport->deleteInbound($id, $idempotencyKey, $this->project->ref);
    }
}
