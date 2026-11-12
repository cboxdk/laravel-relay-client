<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `mail.blocked`: an outbound mail was stored but is not on its way. `$state` says which
 * way: `held` (it waits, and whoever holds it may let it go) or `blocked` (never sent:
 * every recipient is suppressed, or it was discarded). `$heldBy` is who holds a held mail:
 * `project` (a threshold of its own, which it may release) or `relay` (Relay's guard
 * against abuse; only Relay's operator releases it). `$reason` says why. Relay also sends
 * it when a mail is held after it was accepted. `$id` is the mail's
 * (`Relay::mail($event->id)`), `$recipients` how many it was addressed to and
 * `$suppressed` how many of them are on the suppression list. `$state` and `$heldBy` are
 * null on an event from before Relay sent them.
 */
readonly class MailBlocked implements RelayEvent
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
        return 'mail.blocked';
    }

    /** Whether the mail waits and may still be let go, rather than never being sent. */
    public function held(): bool
    {
        return $this->state === 'held';
    }

    /** Whether Relay's own guard holds it: only Relay's operator releases it. */
    public function heldByRelay(): bool
    {
        return $this->heldBy === 'relay';
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
