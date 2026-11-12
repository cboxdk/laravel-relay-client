<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Store;

use Cbox\Relay\Client\Contracts\MessageStore;
use Cbox\Relay\Client\Data\Mail;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/** Keeps nothing and touches no database: the store when `relay.store.enabled` is off. */
class NullMessageStore implements MessageStore
{
    public function enabled(): bool
    {
        return false;
    }

    public function recordSent(Mail $mail, OutgoingMail $outgoing, ?string $project = null, ?string $connection = null): void {}

    public function describe(string $mailId, ?string $mailable): void {}

    public function recordEvent(IncomingWebhook $webhook, ?string $connection = null): bool
    {
        return true;
    }
}
