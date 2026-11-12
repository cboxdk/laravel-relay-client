<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/** `endpoint.disabled`: Relay switched a webhook endpoint off, for `$reason`. Nothing is sent to it until it is resumed. */
readonly class EndpointDisabled implements RelayEvent
{
    public function __construct(
        public string $endpointId,
        public ?string $projectId = null,
        public string $reason = '',
    ) {}

    public static function type(): string
    {
        return 'endpoint.disabled';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'endpoint_id'),
            Payload::nullableString($data, 'project_id'),
            Payload::string($data, 'reason'),
        );
    }
}
