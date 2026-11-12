<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/** `mail.complained`: a recipient reported a mail as spam. The address is now on the suppression list. */
readonly class MailComplained implements RelayEvent
{
    public function __construct(
        public string $id,
        public string $scopeId,
        public string $recipient,
    ) {}

    public static function type(): string
    {
        return 'mail.complained';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'id'),
            Payload::string($data, 'scope_id'),
            Payload::string($data, 'recipient'),
        );
    }
}
