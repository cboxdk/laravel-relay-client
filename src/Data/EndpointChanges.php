<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * What to change on a webhook endpoint. Only what is set is sent, and only that
 * changes. `$clearDescription` sends `description: null`, which removes it.
 */
readonly class EndpointChanges
{
    /**
     * @param  list<string>|null  $eventTypes
     */
    public function __construct(
        public ?string $url = null,
        public ?array $eventTypes = null,
        public ?string $description = null,
        public bool $clearDescription = false,
        public ?Envelope $envelope = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = Payload::compact([
            'url' => $this->url,
            'event_types' => $this->eventTypes,
            'description' => $this->description,
            'envelope' => $this->envelope?->value,
        ]);

        if ($this->clearDescription && $this->description === null) {
            $body['description'] = null;
        }

        return $body;
    }
}
