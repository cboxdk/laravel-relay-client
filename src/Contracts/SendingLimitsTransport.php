<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Contracts;

use Cbox\Relay\Client\Data\SendingLimitRequest;
use Cbox\Relay\Client\Data\SendingLimits;
use Cbox\Relay\Client\Exceptions\RelayException;

/**
 * The sending-limit calls, which need the ability `limits:request`. They live beside
 * {@see RelayTransport} rather than in it, so a transport of your own written against
 * 0.2.0 keeps working: `Relay::sendingLimits()` needs a transport that implements this
 * too, as the package's own and its fake do.
 *
 * @throws RelayException on every method, as on {@see RelayTransport}
 */
interface SendingLimitsTransport
{
    /** `GET /v1/sending-limits` */
    public function sendingLimits(?string $project = null): SendingLimits;

    /** `POST /v1/sending-limit-requests` (201; `approved` when granted at once, else `pending`; 409 `limit_request_open`) */
    public function createSendingLimitRequest(int $hour, int $day, string $reason, ?string $idempotencyKey = null, ?string $project = null): SendingLimitRequest;

    /**
     * `GET /v1/sending-limit-requests`: newest first, at most 50.
     *
     * @return list<SendingLimitRequest>
     */
    public function listSendingLimitRequests(?string $project = null): array;

    /** `GET /v1/sending-limit-requests/{id}`; null on 404. */
    public function findSendingLimitRequest(string $id, ?string $project = null): ?SendingLimitRequest;
}
