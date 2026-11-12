<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use Cbox\Relay\Client\Exceptions\RelayMalformedResponse;

/**
 * The answer to `GET /v1/whoami`: whose key this is, on which environment, for which
 * scope the call acted (null for an organization key acting for the organization) and
 * what the key may do.
 */
readonly class Identity
{
    public function __construct(
        public Organization $organization,
        public Environment $environment,
        public ApiKey $key,
        public ?ScopeReference $scope = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $environment = Environment::tryFrom(Payload::string($data, 'environment'))
            ?? throw new RelayMalformedResponse("Cbox Relay answered with a body this client cannot read: 'environment' should be live or test.");
        $scope = Payload::nullableMap($data, 'scope');

        return new self(
            Organization::fromArray(Payload::map($data, 'organization')),
            $environment,
            ApiKey::fromArray(Payload::map($data, 'key')),
            $scope === null ? null : ScopeReference::fromArray($scope),
        );
    }

    /** Whether the call acted for one scope rather than for the whole organization. */
    public function scoped(): bool
    {
        return $this->scope !== null;
    }

    public function can(string $ability): bool
    {
        return $this->key->can($ability);
    }

    public function live(): bool
    {
        return $this->environment === Environment::Live;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'organization' => $this->organization->toArray(),
            'environment' => $this->environment->value,
            'scope' => $this->scope?->toArray(),
            'key' => $this->key->toArray(),
        ];
    }
}
