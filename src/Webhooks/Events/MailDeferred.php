<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `mail.deferred`: a recipient's server did not take a mail now, and Relay will try again.
 * Nothing is lost yet: it ends in `mail.delivered` or `mail.bounced`. `$status` is the
 * server's code (`4.7.1`, or `451` when it gave no enhanced one, or null), `$diagnostic`
 * what it answered. Relay sends it at most once an hour for one recipient of a mail.
 */
readonly class MailDeferred implements RelayEvent
{
    /**
     * @param  list<string>  $tags  the tags the mail was sent with
     * @param  array<string, string>  $metadata  the metadata the mail was sent with
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $recipient,
        public ?string $status = null,
        public ?string $diagnostic = null,
        public array $tags = [],
        public array $metadata = [],
    ) {}

    public static function type(): string
    {
        return 'mail.deferred';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            projectId: Payload::string($data, 'project_id'),
            recipient: Payload::string($data, 'recipient'),
            status: Payload::nullableString($data, 'status'),
            diagnostic: Payload::nullableString($data, 'diagnostic'),
            tags: Labels::tags($data),
            metadata: Labels::metadata($data),
        );
    }
}
