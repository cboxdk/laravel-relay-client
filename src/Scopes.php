<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\Scope;

/** The scopes of the organization the key belongs to. Reached through `Relay::scopes()`. */
class Scopes
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ?string $actingScope = null,
    ) {}

    /**
     * Make sure the scope exists, under the product's own id for it. Idempotent: call it
     * as often as you like. `$scope->created` tells whether this call created it. A null
     * `$name` or `$settings` is not sent, so an existing scope keeps what it has.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public function put(string $externalRef, ?string $name = null, ?array $settings = null, ?string $idempotencyKey = null): Scope
    {
        return $this->transport->putScope($externalRef, $name, $settings, $idempotencyKey, $this->actingScope);
    }

    /** The scope by external ref or `scp_…` id; null when there is none. */
    public function find(string $ref): ?Scope
    {
        return $this->transport->findScope($ref, $this->actingScope);
    }

    /**
     * @return Page<Scope>
     */
    public function list(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->transport->listScopes($limit, $cursor, $this->actingScope);
    }
}
