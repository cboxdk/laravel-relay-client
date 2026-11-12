<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `mail.discarded`: a held mail was discarded and is never sent; its content is removed.
 * `$by` says who discarded it: today always `project`, the sender.
 */
readonly class MailDiscarded implements RelayEvent
{
    /**
     * @param  list<string>  $tags  the tags the mail was sent with
     * @param  array<string, string>  $metadata  the metadata the mail was sent with
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $by = 'project',
        public array $tags = [],
        public array $metadata = [],
    ) {}

    public static function type(): string
    {
        return 'mail.discarded';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            projectId: Payload::string($data, 'project_id'),
            by: Payload::string($data, 'by'),
            tags: Labels::tags($data),
            metadata: Labels::metadata($data),
        );
    }
}
