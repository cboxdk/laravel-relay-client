<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\Suppression;
use Cbox\Relay\Client\Data\SuppressionReason;

/** The addresses a project never sends mail to. Reached through `Relay::suppressions()`. */
class Suppressions
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingProject $project = new ActingProject,
    ) {}

    /** Never send to the address again. Adding it twice keeps its first reason (`$suppression->created` is then false). */
    public function add(string $address, ?SuppressionReason $reason = null, ?string $idempotencyKey = null): Suppression
    {
        return $this->transport->createSuppression($address, $reason, $idempotencyKey, $this->project->ref);
    }

    /**
     * @return Page<Suppression>
     */
    public function list(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->transport->listSuppressions($limit, $cursor, $this->project->ref);
    }

    /** Allow sending to the address again. */
    public function remove(string $address): void
    {
        $this->transport->deleteSuppression($address, $this->project->ref);
    }
}
