<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Contracts\SendingLimitsTransport;
use Cbox\Relay\Client\Data\SendingLimitRequest;
use Cbox\Relay\Client\Data\SendingLimits as Limits;
use Cbox\Relay\Client\Exceptions\RelayNotConfigured;

/**
 * How much a project may send, and asking for more. Reached through
 * `Relay::sendingLimits()`. The key needs the ability `limits:request`.
 */
class SendingLimits
{
    public function __construct(
        private readonly RelayTransport $transport,
        private readonly ActingProject $project = new ActingProject,
    ) {}

    /** The project's limits per hour and per day, what it has used, its standing and the last moves. */
    public function current(): Limits
    {
        return $this->limits()->sendingLimits($this->project->ref);
    }

    /**
     * Ask for higher limits, in recipients per hour and per day. Neither may be under the
     * current one and one must be over it; `$reason` (10 to 500 characters) says what the
     * mail is and who gets it. The answer is `approved` when Relay granted it at once,
     * otherwise `pending` until Relay's operator answers. One request per project can be
     * open: a second throws {@see Exceptions\RelayConflict} (`limit_request_open`).
     */
    public function request(int $hour, int $day, string $reason, ?string $idempotencyKey = null): SendingLimitRequest
    {
        return $this->limits()->createSendingLimitRequest($hour, $day, $reason, $idempotencyKey, $this->project->ref);
    }

    /**
     * The project's requests, newest first, at most 50.
     *
     * @return list<SendingLimitRequest>
     */
    public function requests(): array
    {
        return $this->limits()->listSendingLimitRequests($this->project->ref);
    }

    /** One request, to see whether it was answered; null when there is none with that id. */
    public function findRequest(string $id): ?SendingLimitRequest
    {
        return $this->limits()->findSendingLimitRequest($id, $this->project->ref);
    }

    private function limits(): SendingLimitsTransport
    {
        return $this->transport instanceof SendingLimitsTransport
            ? $this->transport
            : throw new RelayNotConfigured('The bound Relay transport does not make the sending-limit calls: implement '.SendingLimitsTransport::class.' on it.');
    }
}
