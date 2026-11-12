<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * How much a project may send, and has. Relay sets the limits once a day from the
 * project's own normal use. `$standing` is how its sending looks to Relay (`good`, `fair`,
 * `new`, `poor`; null until Relay has looked), `$openRequest` the request that waits for
 * an answer, and `$changes` the last ten moves, newest first.
 */
readonly class SendingLimits
{
    /**
     * @param  list<SendingLimitChange>  $changes
     */
    public function __construct(
        public SendingLimitWindow $hour,
        public SendingLimitWindow $day,
        public ?string $standing = null,
        public ?SendingLimitRequest $openRequest = null,
        public array $changes = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $request = Payload::nullableMap($data, 'open_request');

        return new self(
            hour: SendingLimitWindow::fromArray(Payload::map($data, 'hour')),
            day: SendingLimitWindow::fromArray(Payload::map($data, 'day')),
            standing: Payload::nullableString($data, 'standing'),
            openRequest: $request === null ? null : SendingLimitRequest::fromArray($request),
            changes: array_map(SendingLimitChange::fromArray(...), Payload::optionalMaps($data, 'changes')),
        );
    }

    /** How many more recipients the project may send to right now: what is left of the tighter window. */
    public function remaining(): int
    {
        return min($this->hour->remaining(), $this->day->remaining());
    }
}
