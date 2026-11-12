<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `project.outbound_watched`: the project sends unlike itself (`$cause` is `spike`) or
 * keeps sending mail the filter doubts (`signals`). Its mail leaves through the probation
 * pool and some of it is held for a look. It is not a pause.
 */
readonly class ProjectOutboundWatched implements RelayEvent
{
    public function __construct(
        public string $projectId,
        public string $reason = '',
        public string $cause = '',
    ) {}

    public static function type(): string
    {
        return 'project.outbound_watched';
    }

    public static function fromArray(array $data): self
    {
        return new self(Payload::string($data, 'project_id'), Payload::string($data, 'reason'), Payload::string($data, 'cause'));
    }
}
