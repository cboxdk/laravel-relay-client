<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;
use DateTimeImmutable;

/**
 * `mail.opened`: a mail client fetched the tracking image of a mail. It names the mail,
 * never a recipient: every recipient gets the same image. It is a weak signal: Apple Mail
 * and Gmail fetch images through their own servers, often before or without anybody
 * reading the mail, and other clients fetch none until asked. Relay sends it at most once
 * an hour for one mail.
 */
readonly class MailOpened implements RelayEvent
{
    /**
     * @param  list<string>  $tags  the tags the mail was sent with
     * @param  array<string, string>  $metadata  the metadata the mail was sent with
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public DateTimeImmutable $openedAt,
        public array $tags = [],
        public array $metadata = [],
    ) {}

    public static function type(): string
    {
        return 'mail.opened';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'id'),
            Payload::string($data, 'project_id'),
            Payload::date($data, 'opened_at'),
            tags: Labels::tags($data),
            metadata: Labels::metadata($data),
        );
    }
}
