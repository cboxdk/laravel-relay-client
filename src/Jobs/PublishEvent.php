<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Jobs;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\NewEvent;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Publishes one event to Relay from the queue. The idempotency key was fixed when the
 * job was dispatched, so however often the job runs, Relay publishes the event once.
 *
 * - 403, 404, 413 and 422 will not get better by trying again: the job fails at once.
 * - 429 is released for as long as Relay's `Retry-After` asks.
 * - 5xx, a connection error and everything else is retried with the backoff, up to
 *   `$tries` attempts.
 */
class PublishEvent implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** The answers no retry changes. */
    public const array PERMANENT = [403, 404, 413, 422];

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|null  $occurredAt  ISO 8601
     * @param  list<int>  $backoff  seconds before the second attempt, the third, …; the queue worker reads it
     */
    public function __construct(
        public string $type,
        public array $payload,
        public string $idempotencyKey,
        public ?string $occurredAt = null,
        public ?string $scope = null,
        int $tries = 5,
        public array $backoff = [10, 30, 120, 600],
        public ?string $subject = null,
    ) {
        $this->tries = max(1, $tries);
    }

    /**
     * @param  list<int>  $backoff
     */
    public static function for(NewEvent $event, ?string $scope = null, int $tries = 5, array $backoff = [10, 30, 120, 600]): self
    {
        return new self(
            $event->type,
            $event->payload,
            $event->idempotencyKey ?? self::newIdempotencyKey(),
            $event->occurredAt?->format(DATE_ATOM),
            $scope,
            $tries,
            $backoff,
            $event->subject,
        );
    }

    /** A key no other publish has: 32 random hex characters behind `pub_`. */
    public static function newIdempotencyKey(): string
    {
        return 'pub_'.bin2hex(random_bytes(16));
    }

    public function handle(RelayTransport $relay): void
    {
        try {
            $relay->publishEvent($this->event(), $this->scope);
        } catch (RelayRateLimited $e) {
            if ($this->attempts() >= $this->tries) {
                throw $e;
            }

            $this->release($e->retryAfter ?? $this->delayAfter($this->attempts()));
        } catch (RelayException $e) {
            if (! in_array($e->status(), self::PERMANENT, true)) {
                throw $e;
            }

            $this->fail($e);
        }
    }

    public function event(): NewEvent
    {
        return new NewEvent(
            $this->type,
            $this->payload,
            $this->idempotencyKey,
            $this->occurredAt === null ? null : new DateTimeImmutable($this->occurredAt),
            $this->subject,
        );
    }

    /** The backoff after the given attempt; the last value repeats when attempts outnumber it. */
    private function delayAfter(int $attempt): int
    {
        return $this->backoff === [] ? 0 : $this->backoff[min(max($attempt, 1), count($this->backoff)) - 1];
    }
}
