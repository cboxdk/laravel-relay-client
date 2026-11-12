<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `mail.released`: a held mail was let go and is on its way again. `$by` says who let it
 * go: `project` (the sender), `operator` (Relay's operator) or `relay` (Relay's own second
 * look found nothing wrong). `$status` is what the mail became: `queued` or `scheduled`.
 */
readonly class MailReleased implements RelayEvent
{
    /**
     * @param  list<string>  $tags  the tags the mail was sent with
     * @param  array<string, string>  $metadata  the metadata the mail was sent with
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $by = 'project',
        public string $status = 'queued',
        public array $tags = [],
        public array $metadata = [],
    ) {}

    public static function type(): string
    {
        return 'mail.released';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            projectId: Payload::string($data, 'project_id'),
            by: Payload::string($data, 'by'),
            status: Payload::string($data, 'status'),
            tags: Labels::tags($data),
            metadata: Labels::metadata($data),
        );
    }
}
