<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

/**
 * The project a client acts for: `$ref` goes out as the `Relay-Project` header. Null sends
 * none, and Relay then acts for the project the key is locked to or, for an organization
 * key, for the environment's `default` project, which it makes on first use.
 */
readonly class ActingProject
{
    public ?string $ref;

    public function __construct(?string $ref = null)
    {
        $this->ref = $ref === '' ? null : $ref;
    }

    public function with(?string $ref): self
    {
        return new self($ref);
    }
}
