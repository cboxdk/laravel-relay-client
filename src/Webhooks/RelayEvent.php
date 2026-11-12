<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks;

/**
 * One of Relay's own events, typed: what it tells a product about mail, push, endpoints
 * and projects. Get it from {@see IncomingWebhook::event()}.
 */
interface RelayEvent
{
    /** The event type this class is for, e.g. `mail.received`. */
    public static function type(): string;

    /**
     * @param  array<array-key, mixed>  $data  the `data` of the envelope
     */
    public static function fromArray(array $data): self;
}
