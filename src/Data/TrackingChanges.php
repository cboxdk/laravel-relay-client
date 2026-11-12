<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * What to change in a sending domain's click tracking. Only what is set is sent, and
 * only that changes. `$clearHost` sends `host: null`, which goes back to Relay's shared
 * host for the links.
 */
readonly class TrackingChanges
{
    public function __construct(
        public ?bool $clicks = null,
        public ?string $host = null,
        public bool $clearHost = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $tracking = Payload::compact(['clicks' => $this->clicks, 'host' => $this->host]);

        if ($this->clearHost && $this->host === null) {
            $tracking['host'] = null;
        }

        return ['tracking' => $tracking];
    }
}
