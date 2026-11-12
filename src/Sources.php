<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\Source;
use Cbox\Relay\Client\Data\SourceScheme;
use SensitiveParameter;

/** The providers a scope takes webhooks from. Reached through `Relay::sources()`. */
class Sources
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingScope $scope = new ActingScope,
    ) {}

    /**
     * Take webhooks from a provider. `$name` is yours (lower-case letters, digits and
     * hyphens, at most 40) and names the event type they are forwarded as,
     * `inbound.<name>`. `$secret` is the signing secret the provider showed you; Relay
     * keeps it sealed and never returns it. Give `$source->url` to the provider.
     */
    public function register(string $name, SourceScheme $scheme, #[SensitiveParameter] string $secret, ?string $idempotencyKey = null): Source
    {
        return $this->transport->createSource($name, $scheme, $secret, $idempotencyKey, $this->scope->required());
    }

    /**
     * @return list<Source>
     */
    public function list(): array
    {
        return $this->transport->listSources($this->scope->required());
    }

    /** The source, or null when there is none with that id in the scope. */
    public function find(string $id): ?Source
    {
        return $this->transport->findSource($id, $this->scope->required());
    }

    /** Set the provider's new signing secret. The old one keeps verifying until `$source->previousSecretExpiresAt`. */
    public function rotateSecret(string $id, #[SensitiveParameter] string $secret): Source
    {
        return $this->transport->rotateSourceSecret($id, $secret, $this->scope->required());
    }

    /** Stop accepting calls: the source's URL answers the provider 404 while paused. */
    public function pause(string $id): Source
    {
        return $this->transport->pauseSource($id, $this->scope->required());
    }

    public function resume(string $id): Source
    {
        return $this->transport->resumeSource($id, $this->scope->required());
    }

    /** Stop taking webhooks from the provider for good. Its URL answers 404 from then on. */
    public function delete(string $id): void
    {
        $this->transport->deleteSource($id, $this->scope->required());
    }
}
