<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Exceptions\RelayProjectRequired;

/**
 * The project a client acts for, and whether a call that needs one may go out without.
 * `$lockedByKey` says the API key is a project key: Relay already knows its project, so no
 * `Relay-Project` is needed and none is demanded.
 */
readonly class ActingProject
{
    public ?string $ref;

    public function __construct(?string $ref = null, public bool $lockedByKey = false)
    {
        $this->ref = $ref === '' ? null : $ref;
    }

    /**
     * The project for a call that only makes sense for one project.
     *
     * @throws RelayProjectRequired when none is named and the key is not locked to one
     */
    public function required(): ?string
    {
        if ($this->ref === null && ! $this->lockedByKey) {
            throw new RelayProjectRequired;
        }

        return $this->ref;
    }

    public function with(?string $ref): self
    {
        return new self($ref, $this->lockedByKey);
    }
}
