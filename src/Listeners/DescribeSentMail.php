<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Listeners;

use Cbox\Relay\Client\Contracts\MessageStore;
use Cbox\Relay\Client\Mail\RelayMailTransport;
use Illuminate\Mail\Events\MessageSent;

/**
 * After Laravel sent a mail through the `relay` transport: reads the id Relay gave it
 * (`X-Relay-Mail-Id`) and tells the store which mailable or notification the mail came
 * from, so the kept row can be found from the app's side. Registered only while the
 * store is enabled.
 */
class DescribeSentMail
{
    public function __construct(private readonly MessageStore $store) {}

    public function handle(MessageSent $event): void
    {
        $id = trim($event->message->getHeaders()->get(RelayMailTransport::MAIL_ID_HEADER)?->getBodyAsString() ?? '');

        if ($id === '' || ! $this->store->enabled()) {
            return;
        }

        $source = $event->data['__laravel_mailable'] ?? $event->data['__laravel_notification'] ?? null;

        $this->store->describe($id, is_string($source) ? $source : null);
    }
}
