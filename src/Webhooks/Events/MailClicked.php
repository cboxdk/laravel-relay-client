<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;
use DateTimeImmutable;

/**
 * `mail.clicked`: somebody followed a tracked link in a mail. It names the mail and the
 * link, never a recipient: every recipient of a mail gets the same link. Relay sends it
 * at most once a minute for one link, however often the link is followed.
 */
readonly class MailClicked implements RelayEvent
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $linkId,
        public string $url,
        public DateTimeImmutable $clickedAt,
    ) {}

    public static function type(): string
    {
        return 'mail.clicked';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'id'),
            Payload::string($data, 'project_id'),
            Payload::string($data, 'link_id'),
            Payload::string($data, 'url'),
            Payload::date($data, 'clicked_at'),
        );
    }
}
