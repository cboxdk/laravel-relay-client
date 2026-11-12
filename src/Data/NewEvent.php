<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

use Cbox\Relay\Client\Exceptions\RelayInvalid;
use DateTimeImmutable;
use DateTimeInterface;
use stdClass;

/**
 * An event to publish. `$idempotencyKey` is what makes a repeat harmless: Relay stores
 * the first event with a key and answers every later one with it as a duplicate.
 *
 * `$subject` is your own id for the person the event is about (`contact_42`, not a name
 * or an address). It is stored with the event, never sent to a receiver, and is what an
 * erasure finds the event by. Visible ASCII without spaces, at most 191 characters;
 * anything else throws {@see RelayInvalid} here, before a job is queued for it.
 */
readonly class NewEvent
{
    public ?DateTimeImmutable $occurredAt;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public array $payload = [],
        public ?string $idempotencyKey = null,
        ?DateTimeInterface $occurredAt = null,
        public ?string $subject = null,
    ) {
        if ($subject !== null && preg_match('/\A[\x21-\x7E]{1,191}\z/', $subject) !== 1) {
            $message = 'The subject of an event is 1 to 191 visible ASCII characters without spaces.';

            throw new RelayInvalid($message, ['subject' => [$message]]);
        }

        $this->occurredAt = $occurredAt === null ? null : DateTimeImmutable::createFromInterface($occurredAt);
    }

    public function withIdempotencyKey(string $key): self
    {
        return new self($this->type, $this->payload, $key, $this->occurredAt, $this->subject);
    }

    /**
     * The request body. An empty payload goes out as the JSON object `{}`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Payload::compact([
            'type' => $this->type,
            'payload' => $this->payload === [] ? new stdClass : $this->payload,
            'idempotency_key' => $this->idempotencyKey,
            'occurred_at' => $this->occurredAt === null ? null : Payload::iso($this->occurredAt),
            'subject' => $this->subject,
        ]);
    }
}
