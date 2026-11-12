<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing\Concerns;

use Cbox\Relay\Client\Data\BrowserSubscription;
use Cbox\Relay\Client\Data\PushMessage;
use Cbox\Relay\Client\Data\PushSubscription;
use Cbox\Relay\Client\Data\PushUrgency;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use Cbox\Relay\Client\Testing\SentPush;
use PHPUnit\Framework\Assert;

/** Web Push, in memory: subscriptions are stored, messages are recorded and stay `queued` until the test says otherwise. */
trait FakesPush
{
    /** @var array<string, array<string, PushSubscription>> by project, then id */
    private array $pushSubscriptions = [];

    /** @var array<string, string> project and browser endpoint => subscription id */
    private array $pushEndpoints = [];

    /** @var array<string, array<string, PushMessage>> by project, then id */
    private array $pushMessages = [];

    /** @var list<SentPush> */
    private array $pushed = [];

    public function pushKey(?string $project = null): string
    {
        $this->record(new RecordedCall(Operation::PushKey, $project));

        return 'fake-vapid-public-key-'.($this->bucket($project) ?: 'key');
    }

    public function listPushSubscriptions(?string $ref = null, ?string $project = null): array
    {
        $this->record(new RecordedCall(Operation::ListPushSubscriptions, $project, $ref));

        return array_values(array_filter(
            array_reverse($this->pushSubscriptions[$this->bucket($project)] ?? []),
            static fn (PushSubscription $subscription): bool => $ref === null || $ref === '' || $subscription->ref === $ref,
        ));
    }

    public function createPushSubscription(BrowserSubscription $subscription, ?string $ref = null, ?string $idempotencyKey = null, ?string $project = null): PushSubscription
    {
        $this->record(new RecordedCall(Operation::CreatePushSubscription, $project, $ref, idempotencyKey: $idempotencyKey, subject: $subscription));
        $bucket = $this->bucket($project);
        $fingerprint = $this->fingerprint(Operation::CreatePushSubscription, $bucket, [$subscription, $ref]);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof PushSubscription) {
            return $replayed;
        }

        $host = parse_url($subscription->endpoint, PHP_URL_HOST);

        if (! str_starts_with($subscription->endpoint, 'https://') || ! is_string($host)) {
            throw $this->invalid('endpoint', 'The endpoint must be an https address at a push service.');
        }

        $known = $this->pushSubscriptions[$bucket][$this->pushEndpoints[$bucket.'|'.$subscription->endpoint] ?? ''] ?? null;

        if ($known !== null) {
            // The same browser again keeps its id; its reference follows what was sent.
            $updated = new PushSubscription($known->id, $known->service, $known->createdAt, $ref ?? $known->ref, $known->lastDeliveredAt);

            return $this->remember($idempotencyKey, $fingerprint, $this->pushSubscriptions[$bucket][$known->id] = $updated);
        }

        $stored = new PushSubscription('psb_fake_'.$this->nextNumber(), $host, $this->now(), $ref);

        $this->pushSubscriptions[$bucket][$stored->id] = $stored;
        $this->pushEndpoints[$bucket.'|'.$subscription->endpoint] = $stored->id;

        // Only this answer says "created".
        return $this->remember($idempotencyKey, $fingerprint, new PushSubscription($stored->id, $stored->service, $stored->createdAt, $stored->ref, created: true));
    }

    public function deletePushSubscription(string $id, ?string $project = null): void
    {
        $this->record(new RecordedCall(Operation::DeletePushSubscription, $project, $id));
        $bucket = $this->bucket($project);

        if (! isset($this->pushSubscriptions[$bucket][$id])) {
            throw $this->missing('push_subscription_not_found', 'No push subscription with that id exists in this project.');
        }

        $this->forgetPushSubscription($bucket, $id);
    }

    public function sendPush(string $subscription, string|array $payload, ?int $ttl = null, ?PushUrgency $urgency = null, ?string $topic = null, ?string $idempotencyKey = null, ?string $project = null): PushMessage
    {
        $push = new SentPush($subscription, $payload, $ttl, $urgency, $topic, $project);

        $this->record(new RecordedCall(Operation::SendPush, $project, $subscription, idempotencyKey: $idempotencyKey, subject: $push));
        $bucket = $this->bucket($project);
        $fingerprint = $this->fingerprint(Operation::SendPush, $bucket, $push);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof PushMessage) {
            return $replayed;
        }

        if (! isset($this->pushSubscriptions[$bucket][$subscription])) {
            throw $this->missing('push_subscription_not_found', 'No push subscription with that id exists in this project.');
        }

        if (strlen(is_string($payload) ? $payload : (string) json_encode($payload)) > 3000) {
            throw $this->invalid('payload', 'The payload may be at most 3000 bytes.');
        }

        $message = new PushMessage('psm_fake_'.$this->nextNumber(), $subscription, 'queued', $this->now());

        $this->pushMessages[$bucket][$message->id] = $message;
        $this->pushed[] = $push;

        return $this->remember($idempotencyKey, $fingerprint, $message);
    }

    public function findPush(string $id, ?string $project = null): ?PushMessage
    {
        $this->record(new RecordedCall(Operation::FindPush, $project, $id));

        return $this->pushMessages[$this->bucket($project)][$id] ?? null;
    }

    /**
     * Store a subscription for a project (stored first if it is not there) as if a browser
     * had subscribed earlier, without recording a call.
     */
    public function seedPushSubscription(string $project, ?string $ref = null, string $service = 'fcm.googleapis.com'): PushSubscription
    {
        $bucket = ($this->project($project) ?? $this->seedProject($project))->id;
        $stored = new PushSubscription('psb_fake_'.$this->nextNumber(), $service, $this->now(), $ref);

        return $this->pushSubscriptions[$bucket][$stored->id] = $stored;
    }

    /**
     * Set what became of a push message, as the queue would have. `expired` also removes
     * the subscription, as Relay does when the push service says it is gone.
     */
    public function markPush(string $id, string $status, ?int $responseCode = null, ?string $error = null): static
    {
        foreach ($this->pushMessages as $bucket => $messages) {
            $message = $messages[$id] ?? null;

            if ($message === null) {
                continue;
            }

            $this->pushMessages[$bucket][$id] = new PushMessage($message->id, $message->subscriptionId, $status, $message->createdAt, $message->attempts + 1, $responseCode, $error, $status === 'queued' ? null : $this->now());

            if ($status === 'expired') {
                $this->forgetPushSubscription($bucket, $message->subscriptionId);
            }
        }

        return $this;
    }

    /**
     * The push messages that were sent, in order.
     *
     * @return list<SentPush>
     */
    public function pushed(): array
    {
        return $this->pushed;
    }

    /**
     * A push message was sent (to that subscription, and matching `$where`).
     *
     * @param  (callable(SentPush): bool)|null  $where
     */
    public function assertPushed(?string $subscription = null, ?callable $where = null): void
    {
        $matching = array_filter(
            $this->pushed,
            static fn (SentPush $push): bool => ($subscription === null || $push->subscription === $subscription) && ($where === null || $where($push)),
        );

        Assert::assertNotEmpty($matching, 'No push message'.($subscription === null ? '' : " to '{$subscription}'").($where === null ? '' : ' matching the condition').' was sent through Cbox Relay.');
    }

    public function assertPushedTimes(int $times): void
    {
        Assert::assertCount($times, $this->pushed, "Cbox Relay was not asked to send {$times} push message(s).");
    }

    public function assertNothingPushed(): void
    {
        Assert::assertCount(0, $this->pushed, 'Push messages were sent through Cbox Relay.');
    }

    private function forgetPushSubscription(string $bucket, string $id): void
    {
        unset($this->pushSubscriptions[$bucket][$id]);

        $this->pushEndpoints = array_filter($this->pushEndpoints, static fn (string $known): bool => $known !== $id);
    }
}
