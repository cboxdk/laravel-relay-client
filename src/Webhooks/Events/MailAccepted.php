<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `mail.accepted`: an outbound mail was stored and queued for sending.
 * `$id` is the mail's (`Relay::mail($event->id)`), `$recipients` how many it was
 * addressed to and `$suppressed` how many of them are on the suppression list.
 * `$state` is `accepted` (null on an event from before Relay sent it) and `$heldBy` null.
 */
readonly class MailAccepted implements RelayEvent
{
    /**
     * @param  list<string>  $tags  the tags the mail was sent with
     * @param  array<string, string>  $metadata  the metadata the mail was sent with
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $from,
        public int $recipients = 0,
        public int $suppressed = 0,
        public string $status = '',
        public ?string $reason = null,
        public array $tags = [],
        public array $metadata = [],
        public ?string $state = null,
        public ?string $heldBy = null,
    ) {}

    public static function type(): string
    {
        return 'mail.accepted';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            projectId: Payload::string($data, 'project_id'),
            from: Payload::string($data, 'from'),
            recipients: Payload::int($data, 'recipients'),
            suppressed: Payload::int($data, 'suppressed'),
            status: Payload::string($data, 'status'),
            reason: Payload::nullableString($data, 'reason'),
            tags: Labels::tags($data),
            metadata: Labels::metadata($data),
            state: Payload::nullableString($data, 'state'),
            heldBy: Payload::nullableString($data, 'held_by'),
        );
    }
}
