<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `mail.delivered`: a recipient's server accepted a mail. That is all it says: what the
 * server did with it next, inbox or junk, it does not tell.
 */
readonly class MailDelivered implements RelayEvent
{
    /**
     * @param  list<string>  $tags  the tags the mail was sent with
     * @param  array<string, string>  $metadata  the metadata the mail was sent with
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $recipient,
        public array $tags = [],
        public array $metadata = [],
    ) {}

    public static function type(): string
    {
        return 'mail.delivered';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'id'),
            Payload::string($data, 'project_id'),
            Payload::string($data, 'recipient'),
            tags: Labels::tags($data),
            metadata: Labels::metadata($data),
        );
    }
}
