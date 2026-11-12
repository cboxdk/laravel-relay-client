<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Contracts;

use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\Identity;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\Scope;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Relay;

/**
 * The Relay API, one method per endpoint. Everything else in this package (the
 * {@see Relay} manager, the facade) is a convenience over this
 * contract; bind your own implementation to decorate, record or replace the transport.
 *
 * `$scope` on every method is the scope the call acts for (an external ref or a `scp_…`
 * id), sent as the `Relay-Scope` header; null acts as the key itself. Every method
 * throws a {@see RelayException} when Relay refuses or cannot be reached.
 */
interface RelayTransport
{
    /** `GET /v1/whoami` */
    public function whoami(?string $scope = null): Identity;

    /**
     * `PUT /v1/scopes/{external_ref}`: create the scope or update the one that exists.
     * A null `$name` or `$settings` is left out of the request, so Relay keeps what it has.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public function putScope(string $externalRef, ?string $name = null, ?array $settings = null, ?string $idempotencyKey = null, ?string $scope = null): Scope;

    /** `GET /v1/scopes/{ref}`; null when Relay answers 404. */
    public function findScope(string $ref, ?string $scope = null): ?Scope;

    /**
     * `GET /v1/scopes`
     *
     * @return Page<Scope>
     */
    public function listScopes(?int $limit = null, ?string $cursor = null, ?string $scope = null): Page;

    /**
     * `GET /v1/deliveries`
     *
     * @return Page<Delivery>
     */
    public function listDeliveries(DeliveryFilter $filter = new DeliveryFilter, ?int $limit = null, ?string $cursor = null, ?string $scope = null): Page;
}
