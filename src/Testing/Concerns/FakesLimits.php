<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing\Concerns;

use Cbox\Relay\Client\Data\MailRecipient;
use Cbox\Relay\Client\Data\Problem;
use Cbox\Relay\Client\Data\SendingLimitChange;
use Cbox\Relay\Client\Data\SendingLimitDecision;
use Cbox\Relay\Client\Data\SendingLimitRequest;
use Cbox\Relay\Client\Data\SendingLimits;
use Cbox\Relay\Client\Data\SendingLimitWindow;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayRateLimited;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * Sending limits, in memory. A project has Relay's starting limits until a test seeds
 * others; a request waits (`pending`) unless the test told the fake to grant at once, and
 * `decideSendingLimitRequest()` answers one as Relay's operator would.
 */
trait FakesLimits
{
    /** Relay's limits for an approved project, in recipients. */
    public const int DEFAULT_HOUR_LIMIT = 500;

    public const int DEFAULT_DAY_LIMIT = 5000;

    /** @var array<string, array{hour: int, day: int, origin: string, standing: string|null}> by project */
    private array $sendingLimits = [];

    /** @var array<string, array{scope: string, retryAfter: int}> by project: every send is refused */
    private array $sendingRefused = [];

    /** @var array<string, array<string, SendingLimitRequest>> by project, then id */
    private array $limitRequests = [];

    /** @var array<string, list<SendingLimitChange>> by project, newest first */
    private array $limitChanges = [];

    private bool $grantsLimitRequests = false;

    public function sendingLimits(?string $project = null): SendingLimits
    {
        $this->record(new RecordedCall(Operation::SendingLimits, $project));
        $bucket = $this->bucket($project);
        $limits = $this->limitsOf($bucket);
        $used = $this->recipientsSent($bucket);

        return new SendingLimits(
            new SendingLimitWindow($limits['hour'], $used['hour'], $limits['origin']),
            new SendingLimitWindow($limits['day'], $used['day'], $limits['origin']),
            $limits['standing'],
            array_find($this->limitRequests[$bucket] ?? [], static fn (SendingLimitRequest $request): bool => $request->pending()),
            array_slice($this->limitChanges[$bucket] ?? [], 0, 10),
        );
    }

    public function createSendingLimitRequest(int $hour, int $day, string $reason, ?string $idempotencyKey = null, ?string $project = null): SendingLimitRequest
    {
        $this->record(new RecordedCall(Operation::CreateSendingLimitRequest, $project, name: $reason, idempotencyKey: $idempotencyKey));
        $bucket = $this->bucket($project);
        $fingerprint = $this->fingerprint(Operation::CreateSendingLimitRequest, $bucket, [$hour, $day, $reason]);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof SendingLimitRequest) {
            return $replayed;
        }

        $limits = $this->limitsOf($bucket);

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw $this->invalid('reason', 'The reason is 10 to 500 characters.');
        }

        if ($hour < 1 || $day < $hour) {
            throw $this->invalid('day', 'The day must be at least the hour.');
        }

        if ($hour < $limits['hour'] || $day < $limits['day'] || ($hour === $limits['hour'] && $day === $limits['day'])) {
            throw $this->invalid('hour', 'Ask for more than the project may send today.');
        }

        if (array_any($this->limitRequests[$bucket] ?? [], static fn (SendingLimitRequest $request): bool => $request->pending())) {
            throw new RelayConflict('Cbox Relay answered 409 Conflict: The project already has a request that waits for an answer.', new Problem(409, 'Conflict', 'The project already has a request that waits for an answer.', self::PROBLEMS.'limit_request_open'));
        }

        $request = new SendingLimitRequest('slr_fake_'.$this->nextNumber(), $bucket, 'pending', $hour, $day, $reason, $this->now());
        $this->limitRequests[$bucket][$request->id] = $request;

        if ($this->grantsLimitRequests) {
            $request = $this->decide($bucket, $request, true, $hour, $day, 'Granted at once: the project is in good standing.', 'auto');
        }

        return $this->remember($idempotencyKey, $fingerprint, $request);
    }

    public function listSendingLimitRequests(?string $project = null): array
    {
        $this->record(new RecordedCall(Operation::ListSendingLimitRequests, $project));

        return array_slice(array_reverse(array_values($this->limitRequests[$this->bucket($project)] ?? [])), 0, 50);
    }

    public function findSendingLimitRequest(string $id, ?string $project = null): ?SendingLimitRequest
    {
        $this->record(new RecordedCall(Operation::FindSendingLimitRequest, $project, $id));

        return $this->limitRequests[$this->bucket($project)][$id] ?? null;
    }

    /**
     * Give a project (stored first if it is not there) limits of its own, as Relay's daily
     * look, a granted request or its operator would have. Records no call.
     */
    public function seedSendingLimits(string $project, int $hour, int $day, string $origin = 'operator', ?string $standing = null): static
    {
        $bucket = ($this->project($project) ?? $this->seedProject($project))->id;
        $this->sendingLimits[$bucket] = ['hour' => $hour, 'day' => max($hour, $day), 'origin' => $origin, 'standing' => $standing];

        return $this;
    }

    /**
     * Refuse every mail from now on with 429 `sending_limited`, as Relay does when a limit
     * is reached: `$scope` is `project`, or `organization` for the limit all the
     * organization's projects share. `$project` null refuses for every project.
     */
    public function limitSending(?string $project = null, string $scope = 'project', int $retryAfter = 3600): static
    {
        $this->sendingRefused[$project === null ? '*' : $this->bucketOf($project)] = ['scope' => $scope, 'retryAfter' => $retryAfter];

        return $this;
    }

    /** There is room again: stop refusing mail for a limit. */
    public function liftSendingLimit(?string $project = null): static
    {
        unset($this->sendingRefused[$project === null ? '*' : $this->bucketOf($project)]);

        return $this;
    }

    /** Grant every request at once, as Relay does for a project in good standing; false makes them wait again. */
    public function grantLimitRequestsAtOnce(bool $grant = true): static
    {
        $this->grantsLimitRequests = $grant;

        return $this;
    }

    /**
     * Answer a waiting request as Relay's operator would. Approved with no numbers grants
     * what was asked. Records no call.
     */
    public function decideSendingLimitRequest(string $id, bool $approve, ?int $hour = null, ?int $day = null, ?string $reason = null): SendingLimitRequest
    {
        foreach ($this->limitRequests as $bucket => $requests) {
            $request = $requests[$id] ?? null;

            if ($request !== null && $request->pending()) {
                return $this->decide($bucket, $request, $approve, $hour ?? $request->hour, $day ?? $request->day, $reason, 'operator');
            }
        }

        throw new InvalidArgumentException("The fake holds no waiting sending-limit request '{$id}'.");
    }

    /**
     * Hold a stored mail as Relay's own guard against abuse does: `quarantined`, held by
     * `relay`. The sender can discard it; releasing it is refused. Records no call.
     */
    public function holdMailByRelay(string $id, string $reason = 'Held by Relay for a second look.'): static
    {
        foreach ($this->mails as $bucket => $mails) {
            if (isset($mails[$id])) {
                $this->mails[$bucket][$id] = $mails[$id]->became('quarantined', $reason, heldBy: 'relay');

                return $this;
            }
        }

        throw new InvalidArgumentException("The fake holds no mail '{$id}'.");
    }

    /**
     * Higher limits were asked for (and the request matches `$where`).
     *
     * @param  (callable(SendingLimitRequest): bool)|null  $where
     */
    public function assertSendingLimitRequested(?callable $where = null): void
    {
        $requests = array_merge(...array_map(array_values(...), array_values($this->limitRequests)));

        Assert::assertTrue(
            array_any($requests, static fn (SendingLimitRequest $request): bool => $where === null || $where($request)),
            $requests === [] ? 'No higher sending limit was asked for.' : 'No request for a higher sending limit matched.',
        );
    }

    public function assertNoSendingLimitRequested(): void
    {
        Assert::assertSame([], array_merge(...array_map(array_keys(...), array_values($this->limitRequests))), 'A higher sending limit was asked for.');
    }

    /** @return array{hour: int, day: int, origin: string, standing: string|null} */
    private function limitsOf(string $bucket): array
    {
        return $this->sendingLimits[$bucket] ?? ['hour' => self::DEFAULT_HOUR_LIMIT, 'day' => self::DEFAULT_DAY_LIMIT, 'origin' => 'default', 'standing' => null];
    }

    /**
     * Recipients the project's stored mail went to in the last hour and the last 24 hours;
     * suppressed recipients do not count, as on the real service.
     *
     * @return array{hour: int, day: int}
     */
    private function recipientsSent(string $bucket): array
    {
        $used = ['hour' => 0, 'day' => 0];
        $now = $this->now();

        foreach ($this->mails[$bucket] ?? [] as $mail) {
            $count = count(array_filter($mail->recipients, static fn (MailRecipient $recipient): bool => $recipient->status !== 'suppressed'));
            $age = $now->getTimestamp() - $mail->createdAt->getTimestamp();
            $used['hour'] += $age < 3600 ? $count : 0;
            $used['day'] += $age < 86400 ? $count : 0;
        }

        return $used;
    }

    /** What Relay refuses a mail for when a limit is reached, or nothing. */
    private function guardSendingLimit(string $bucket, int $recipients): void
    {
        $refused = $this->sendingRefused[$bucket] ?? $this->sendingRefused['*'] ?? null;

        if ($refused === null && isset($this->sendingLimits[$bucket])) {
            $limits = $this->sendingLimits[$bucket];
            $used = $this->recipientsSent($bucket);

            if ($limits['hour'] < $used['hour'] + $recipients || $limits['day'] < $used['day'] + $recipients) {
                $refused = ['scope' => 'project', 'retryAfter' => $limits['day'] < $used['day'] + $recipients ? 86400 : 3600];
            }
        }

        if ($refused === null) {
            return;
        }

        $detail = $refused['scope'] === 'organization'
            ? 'The organization has reached its sending limit.'
            : 'The project has reached its sending limit.';

        throw new RelayRateLimited(
            'Cbox Relay answered 429 Too Many Requests: '.$detail,
            $refused['retryAfter'],
            new Problem(429, 'Too Many Requests', $detail, self::PROBLEMS.'sending_limited', extensions: ['scope' => $refused['scope'], 'retry_after' => $refused['retryAfter']]),
        );
    }

    private function decide(string $bucket, SendingLimitRequest $request, bool $approve, int $hour, int $day, ?string $reason, string $by): SendingLimitRequest
    {
        $decided = new SendingLimitRequest(
            $request->id, $request->projectId, $approve ? 'approved' : 'declined', $request->hour, $request->day, $request->reason, $request->createdAt,
            new SendingLimitDecision($approve ? $hour : null, $approve ? max($hour, $day) : null, $reason, $by, $this->now()),
        );

        if ($approve) {
            $before = $this->limitsOf($bucket);
            $this->sendingLimits[$bucket] = ['hour' => $hour, 'day' => max($hour, $day), 'origin' => 'request', 'standing' => $before['standing']];
            $this->limitChanges[$bucket] ??= [];
            array_unshift($this->limitChanges[$bucket], new SendingLimitChange('request', $hour, max($hour, $day), $before['hour'], $before['day'], $reason ?? 'A request was granted.', $this->now()));
        }

        return $this->limitRequests[$bucket][$request->id] = $decided;
    }
}
