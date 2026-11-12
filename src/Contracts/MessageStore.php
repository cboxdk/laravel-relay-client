<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Contracts;

use Cbox\Relay\Client\Data\Mail;
use Cbox\Relay\Client\Data\OutgoingMail;
use Cbox\Relay\Client\Webhooks\IncomingWebhook;

/**
 * This app's own record of the mail it sent through Relay and of what Relay said about
 * it afterwards, so "what happened to that mail" is a local question. Optional: without
 * `relay.store.enabled` the bound store keeps nothing, and nothing needs a database.
 */
interface MessageStore
{
    /** Whether anything is kept at all. */
    public function enabled(): bool;

    /**
     * Keep a mail Relay accepted. Safe to repeat: the same mail id is one row.
     *
     * @param  string|null  $project  the project the mail was sent for, as this app named it
     * @param  string|null  $connection  the Relay connection it went through
     */
    public function recordSent(Mail $mail, OutgoingMail $outgoing, ?string $project = null, ?string $connection = null): void;

    /** Say which mailable a kept mail came from. Does nothing when the mail is not kept. */
    public function describe(string $mailId, ?string $mailable): void;

    /**
     * Keep one webhook event about a mail and bring the mail's row up to date. A delivery
     * seen before changes nothing.
     *
     * @return bool whether the event was new
     */
    public function recordEvent(IncomingWebhook $webhook, ?string $connection = null): bool;
}
