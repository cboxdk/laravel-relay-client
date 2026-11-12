<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\Project;

/** The projects of the organization the key belongs to. Reached through `Relay::projects()`. */
class Projects
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ?string $actingProject = null,
    ) {}

    /**
     * Make sure the project exists, under the product's own id for it. Idempotent: call it
     * as often as you like. `$project->created` tells whether this call created it. A null
     * `$name` or `$settings` is not sent, so an existing project keeps what it has.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public function put(string $externalRef, ?string $name = null, ?array $settings = null, ?string $idempotencyKey = null): Project
    {
        return $this->transport->putProject($externalRef, $name, $settings, $idempotencyKey, $this->actingProject);
    }

    /** The project by external ref or `prj_…` id; null when there is none. */
    public function find(string $ref): ?Project
    {
        return $this->transport->findProject($ref, $this->actingProject);
    }

    /**
     * @return Page<Project>
     */
    public function list(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->transport->listProjects($limit, $cursor, $this->actingProject);
    }
}
