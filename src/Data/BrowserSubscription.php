<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * A browser's push subscription, as `PushSubscription.toJSON()` gives it in the page.
 * A null `$contentEncoding` is not sent, so Relay's default (`aes128gcm`) applies.
 */
readonly class BrowserSubscription
{
    public function __construct(
        public string $endpoint,
        public string $p256dh,
        public string $auth,
        public ?string $contentEncoding = null,
    ) {}

    /**
     * From what the browser posted to your app: `{"endpoint": …, "keys": {"p256dh": …, "auth": …}}`.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $keys = Payload::map($data, 'keys');

        return new self(
            Payload::string($data, 'endpoint'),
            Payload::string($keys, 'p256dh'),
            Payload::string($keys, 'auth'),
            Payload::nullableString($data, 'content_encoding'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Payload::compact([
            'endpoint' => $this->endpoint,
            'keys' => ['p256dh' => $this->p256dh, 'auth' => $this->auth],
            'content_encoding' => $this->contentEncoding,
        ]);
    }
}
