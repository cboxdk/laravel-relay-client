<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * A webhook endpoint to register. `$eventTypes` takes a type, a group (`order.*`) or
 * `*`. A null `$envelope` or `$signatureScheme` is not sent, so Relay's default applies.
 */
readonly class NewEndpoint
{
    /**
     * @param  list<string>  $eventTypes
     */
    public function __construct(
        public string $url,
        public array $eventTypes,
        public ?string $description = null,
        public ?Envelope $envelope = null,
        public ?SignatureScheme $signatureScheme = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Payload::compact([
            'url' => $this->url,
            'event_types' => $this->eventTypes,
            'description' => $this->description,
            'envelope' => $this->envelope?->value,
            'signature_scheme' => $this->signatureScheme?->value,
        ]);
    }
}
