<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Notifications;

use Cbox\Relay\Client\Data\PushUrgency;

/**
 * What a notification sends through {@see RelayWebPushChannel}. `$payload` is what your
 * service worker receives (an array goes as a JSON object, at most 3000 bytes); the
 * rest are the push options. `$scope` sends for that scope instead of the client's default.
 */
readonly class WebPushMessage
{
    /**
     * @param  string|array<string, mixed>  $payload
     * @param  int|null  $ttl  seconds the push service keeps the message for an offline device
     * @param  string|null  $topic  a newer message with the same topic replaces an undelivered older one
     */
    public function __construct(
        public string|array $payload,
        public ?int $ttl = null,
        public ?PushUrgency $urgency = null,
        public ?string $topic = null,
        public ?string $scope = null,
    ) {}
}
