<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks;

use Cbox\Relay\Client\Data\Envelope;
use Cbox\Relay\Client\Exceptions\RelayMalformedResponse;
use Cbox\Relay\Client\Exceptions\RelayWebhookRejected;
use Cbox\Relay\Client\Webhooks\Events\EndpointDisabled;
use Cbox\Relay\Client\Webhooks\Events\MailAccepted;
use Cbox\Relay\Client\Webhooks\Events\MailBlocked;
use Cbox\Relay\Client\Webhooks\Events\MailBounced;
use Cbox\Relay\Client\Webhooks\Events\MailClicked;
use Cbox\Relay\Client\Webhooks\Events\MailComplained;
use Cbox\Relay\Client\Webhooks\Events\MailReceived;
use Cbox\Relay\Client\Webhooks\Events\ProjectOutboundPaused;
use Cbox\Relay\Client\Webhooks\Events\PushDelivered;
use Cbox\Relay\Client\Webhooks\Events\PushExpired;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\Request;
use JsonException;

/**
 * One webhook delivery from Relay, read from either envelope an endpoint can choose:
 * the `cbox` one or CloudEvents 1.0 structured JSON.
 *
 * `$id` is the event's id, the same on every endpoint, retry and replay. `$deliveryId`
 * is unique per event and endpoint: deduplicate on that. `$sequence` counts up by one
 * per endpoint, so a gap shows a delivery you have not seen.
 *
 * Parsing proves nothing about who sent the body. Verify the signature first
 * ({@see WebhookSignature}, or the `relay.webhook` middleware, which does both).
 */
readonly class IncomingWebhook
{
    public const string REQUEST_ATTRIBUTE = 'relay.webhook';

    /** Relay's own event types and the class each is read into. */
    public const array EVENTS = [
        'mail.received' => MailReceived::class,
        'mail.accepted' => MailAccepted::class,
        'mail.blocked' => MailBlocked::class,
        'mail.bounced' => MailBounced::class,
        'mail.complained' => MailComplained::class,
        'mail.clicked' => MailClicked::class,
        'push.delivered' => PushDelivered::class,
        'push.expired' => PushExpired::class,
        'endpoint.disabled' => EndpointDisabled::class,
        'project.outbound_paused' => ProjectOutboundPaused::class,
    ];

    /**
     * @param  array<string, mixed>  $data  the event's payload, untouched
     * @param  string|null  $projectId  Relay's id (`prj_…`) of the project the event belongs to
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $data = [],
        public Envelope $envelope = Envelope::Cbox,
        public ?DateTimeImmutable $occurredAt = null,
        public ?string $deliveryId = null,
        public ?int $attempt = null,
        public ?int $sequence = null,
        public ?string $projectId = null,
        public ?string $apiVersion = null,
    ) {}

    /**
     * The webhook the `relay.webhook` middleware verified and parsed for this request.
     *
     * @throws RelayWebhookRejected when the request did not pass through the middleware
     */
    public static function from(Request $request): self
    {
        $webhook = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $webhook instanceof self
            ? $webhook
            : throw new RelayWebhookRejected(RelayWebhookRejected::REASON_MISSING_SIGNATURE, 'This request did not pass through the relay.webhook middleware, so nothing about it is verified.');
    }

    /**
     * Read a raw request body. Relay sends one event per delivery.
     *
     * @throws RelayWebhookRejected with `REASON_MALFORMED_BODY` when it is neither envelope
     */
    public static function parse(string $body): self
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw self::malformed('it is not JSON', $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw self::malformed('it is not a JSON object');
        }

        return isset($decoded['specversion']) ? self::fromCloudEvent($decoded) : self::fromCboxEnvelope($decoded);
    }

    /** Whether this is one of Relay's own events rather than one your product published. */
    public function fromRelay(): bool
    {
        return isset(self::EVENTS[$this->type]);
    }

    /**
     * The typed event for one of Relay's own types; null for every other type (your own
     * events come back as you published them, in `$data`).
     *
     * @throws RelayWebhookRejected with `REASON_MALFORMED_BODY` when the data is not what that event carries
     */
    public function event(): ?RelayEvent
    {
        $class = self::EVENTS[$this->type] ?? null;

        if ($class === null) {
            return null;
        }

        try {
            return $class::fromArray($this->data);
        } catch (RelayMalformedResponse $e) {
            throw self::malformed("the data of '{$this->type}' is not what Relay sends for it", $e);
        }
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private static function fromCboxEnvelope(array $body): self
    {
        // The envelope's own word (cboxdk/laravel-webhooks): `scope` holds the project's id as its key.
        $project = self::map($body, 'scope');

        return new self(
            id: self::string($body, 'id'),
            type: self::string($body, 'type'),
            data: self::map($body, 'data'),
            envelope: Envelope::Cbox,
            occurredAt: self::date($body, 'created_at'),
            deliveryId: self::optionalString($body, 'delivery_id'),
            attempt: self::optionalInt($body, 'attempt'),
            sequence: self::optionalInt($body, 'sequence'),
            projectId: self::optionalString($project, 'key'),
            apiVersion: self::optionalString($body, 'api_version'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private static function fromCloudEvent(array $body): self
    {
        // The owner is `partition/key`, or the key alone.
        $subject = self::optionalString($body, 'subject');
        $slash = $subject === null ? false : strrpos($subject, '/');

        return new self(
            id: self::string($body, 'id'),
            type: self::string($body, 'type'),
            data: self::map($body, 'data'),
            envelope: Envelope::CloudEvents,
            occurredAt: self::date($body, 'time'),
            deliveryId: self::optionalString($body, 'deliveryid'),
            attempt: self::optionalInt($body, 'attempt'),
            // CloudEvents carries the sequence as a string of digits.
            sequence: self::optionalInt($body, 'sequence'),
            projectId: $subject === null || $slash === false ? $subject : substr($subject, $slash + 1),
            apiVersion: self::optionalString($body, 'apiversion'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        return self::optionalString($data, $key) ?? throw self::malformed("'{$key}' is missing");
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return match (true) {
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => null,
        };
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<string, mixed>
     */
    private static function map(array $data, string $key): array
    {
        $map = [];

        foreach (is_array($data[$key] ?? null) ? $data[$key] : [] as $name => $value) {
            $map[(string) $name] = $value;
        }

        return $map;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function date(array $data, string $key): ?DateTimeImmutable
    {
        $value = self::optionalString($data, $key);

        try {
            return $value === null ? null : new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private static function malformed(string $why, ?Exception $previous = null): RelayWebhookRejected
    {
        return new RelayWebhookRejected(RelayWebhookRejected::REASON_MALFORMED_BODY, 'The webhook body cannot be read: '.$why.'.', $previous);
    }
}
