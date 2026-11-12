<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Data\ApiKey;
use Cbox\Relay\Client\Data\AttemptOutcome;
use Cbox\Relay\Client\Data\Delivery;
use Cbox\Relay\Client\Data\DeliveryFilter;
use Cbox\Relay\Client\Data\Environment;
use Cbox\Relay\Client\Data\Identity;
use Cbox\Relay\Client\Data\Organization;
use Cbox\Relay\Client\Data\Page;
use Cbox\Relay\Client\Data\PingResult;
use Cbox\Relay\Client\Data\Problem;
use Cbox\Relay\Client\Data\Project;
use Cbox\Relay\Client\Data\ProjectReference;
use Cbox\Relay\Client\Exceptions\RelayConflict;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Exceptions\RelayInvalid;
use Cbox\Relay\Client\Exceptions\RelayNotFound;
use Cbox\Relay\Client\Testing\Concerns\FakesInbound;
use Cbox\Relay\Client\Testing\Concerns\FakesJudge;
use Cbox\Relay\Client\Testing\Concerns\FakesMail;
use Cbox\Relay\Client\Testing\Concerns\FakesPush;
use Cbox\Relay\Client\Testing\Concerns\FakesSources;
use Cbox\Relay\Client\Testing\Concerns\FakesWebhooks;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Assert;

/**
 * Cbox Relay in memory. Projects, event types, endpoints, events, judgments, inbound
 * routes, push subscriptions, sending domains and suppressions are really stored, per
 * project; published events, sent mail and push messages are recorded and go nowhere; the
 * delivery log and received mail hold what the test seeded. Every call is recorded.
 * Nothing leaves the process.
 *
 * Where the fake has to decide something the API description leaves open, the choice is
 * listed in the testing docs (docs/getting-started/testing.md).
 */
class FakeRelay implements RelayTransport
{
    use FakesInbound;
    use FakesJudge;
    use FakesMail;
    use FakesPush;
    use FakesSources;
    use FakesWebhooks;

    /** The page size when a list is read without `limit`. */
    public const int DEFAULT_LIMIT = 50;

    /** What the `type` of every problem the fake answers with starts with, as on the real service. */
    public const string PROBLEMS = 'https://cboxrelay.com/problems/';

    /** @var array<string, Project> by external ref */
    private array $projects = [];

    /** @var list<Delivery> */
    private array $deliveries = [];

    /** @var list<RecordedCall> */
    private array $calls = [];

    /** @var array<string, string> idempotency key => fingerprint of the request it was used for */
    private array $idempotencyKeys = [];

    /** @var array<string, object> idempotency key => the first answer, given again to a repeat */
    private array $idempotentAnswers = [];

    private bool $anyProject = false;

    private bool $dnsPublished = true;

    private Identity $identity;

    private ?RelayException $failure = null;

    private int $sequence = 0;

    public function __construct()
    {
        $this->identity = new Identity(
            new Organization('org_fake', 'Fake organization'),
            Environment::Test,
            new ApiKey('key_fake', 'Fake key', 'rlk_test_fake', [
                'projects:manage', 'deliveries:read', 'judge:run', 'inbound:read', 'inbound:manage', 'push:send', 'push:manage',
                'mail:send', 'domains:manage', 'suppressions:manage', 'events:publish', 'events:read', 'endpoints:manage', 'sources:manage',
            ]),
        );

        $this->pingResult = new PingResult(AttemptOutcome::Success->value, 200, 1);
    }

    public function whoami(?string $project = null): Identity
    {
        $this->record(new RecordedCall(Operation::Whoami, $project));

        $acting = $this->acting($project);

        return $acting === null ? $this->identity : new Identity(
            $this->identity->organization,
            $this->identity->environment,
            $this->identity->key,
            new ProjectReference($acting->id, $acting->externalRef, $acting->name),
        );
    }

    public function putProject(string $externalRef, ?string $name = null, ?array $settings = null, ?string $idempotencyKey = null, ?string $project = null): Project
    {
        $this->record(new RecordedCall(Operation::PutProject, $project, $externalRef, $name, $settings, $idempotencyKey));
        $this->acting($project);

        if ($idempotencyKey !== null) {
            $fingerprint = serialize([$externalRef, $name, $settings]);

            if (($this->idempotencyKeys[$idempotencyKey] ??= $fingerprint) !== $fingerprint) {
                throw new RelayConflict(problem: new Problem(409, 'Conflict', 'The Idempotency-Key was already used for a different request.'));
            }
        }

        $existing = $this->projects[$externalRef] ?? null;

        if ($existing === null) {
            $stored = $this->seedProject($externalRef, $name, $settings ?? []);

            // Only this answer says "created"; what is stored reads back like any other project.
            return new Project($stored->id, $stored->externalRef, $stored->name, $stored->createdAt, $stored->settings, created: true);
        }

        return $this->projects[$externalRef] = new Project(
            id: $existing->id,
            externalRef: $externalRef,
            name: $name ?? $existing->name,
            createdAt: $existing->createdAt,
            settings: $settings ?? $existing->settings,
            suspendedAt: $existing->suspendedAt,
        );
    }

    public function findProject(string $ref, ?string $project = null): ?Project
    {
        $this->record(new RecordedCall(Operation::FindProject, $project, $ref));
        $this->acting($project);

        return $this->project($ref);
    }

    public function listProjects(?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        $this->record(new RecordedCall(Operation::ListProjects, $project, limit: $limit, cursor: $cursor));
        $this->acting($project);

        return $this->page($this->projects(), $limit, $cursor);
    }

    public function listDeliveries(DeliveryFilter $filter = new DeliveryFilter, ?int $limit = null, ?string $cursor = null, ?string $project = null): Page
    {
        $this->record(new RecordedCall(Operation::ListDeliveries, $project, filter: $filter, limit: $limit, cursor: $cursor));

        $acting = $this->acting($project);

        $matching = array_values(array_filter(
            $this->deliveries,
            static fn (Delivery $delivery): bool => ($acting === null || $delivery->projectId === $acting->id) && $filter->matches($delivery),
        ));

        // Newest first, the order of a log.
        usort($matching, static fn (Delivery $a, Delivery $b): int => $b->occurredAt <=> $a->occurredAt);

        return $this->page($matching, $limit, $cursor);
    }

    /**
     * Store a project as if a product had put it earlier, without recording a call.
     *
     * @param  array<string, mixed>  $settings
     */
    public function seedProject(string $externalRef, ?string $name = null, array $settings = [], ?DateTimeInterface $suspendedAt = null): Project
    {
        $existing = $this->projects[$externalRef] ?? null;

        return $this->projects[$externalRef] = new Project(
            id: $existing === null ? 'prj_fake_'.++$this->sequence : $existing->id,
            externalRef: $externalRef,
            name: $name ?? $externalRef,
            createdAt: $existing === null ? Carbon::now()->toDateTimeImmutable() : $existing->createdAt,
            settings: $settings,
            suspendedAt: $suspendedAt === null ? null : DateTimeImmutable::createFromInterface($suspendedAt),
        );
    }

    /** Put these lines in the delivery log, exactly as given. */
    public function seedDeliveries(Delivery ...$deliveries): static
    {
        $this->deliveries = [...$this->deliveries, ...array_values($deliveries)];

        return $this;
    }

    /**
     * Put one line in the delivery log for a project, which is stored first if it is not
     * there. `$status` is Relay's own string; the fake does not know which exist.
     */
    public function seedDeliveryFor(
        string $project,
        string $status,
        string $channel = 'webhook',
        string $target = 'https://example.test/hooks',
        string $subjectType = 'event',
        ?string $subjectId = null,
        ?DateTimeInterface $occurredAt = null,
        int $attempt = 1,
        ?int $responseCode = null,
        ?string $error = null,
        ?int $durationMs = null,
    ): Delivery {
        $owner = $this->project($project) ?? $this->seedProject($project);
        $number = ++$this->sequence;

        $delivery = new Delivery(
            id: 'dlv_fake_'.$number,
            channel: $channel,
            subjectType: $subjectType,
            subjectId: $subjectId ?? 'sub_fake_'.$number,
            target: $target,
            status: $status,
            occurredAt: $occurredAt === null ? Carbon::now()->toDateTimeImmutable() : DateTimeImmutable::createFromInterface($occurredAt),
            projectId: $owner->id,
            attempt: $attempt,
            responseCode: $responseCode,
            error: $error,
            durationMs: $durationMs,
        );

        $this->deliveries[] = $delivery;

        return $delivery;
    }

    /** What `whoami` answers from now on (its project is replaced when a call acts for one). */
    public function actingAs(Identity $identity): static
    {
        $this->identity = $identity;

        return $this;
    }

    /** Every call from now on is recorded and then throws this, as Relay refusing or being down. */
    public function failWith(RelayException $exception): static
    {
        $this->failure = $exception;

        return $this;
    }

    /**
     * Take any project a call names: one that is not stored yet is stored on first use,
     * instead of answering 404. For tests that are not about registering projects.
     */
    public function acceptAnyProject(bool $accept = true): static
    {
        $this->anyProject = $accept;

        return $this;
    }

    /**
     * Whether the DNS records Relay looks for are found when a domain or a domain route
     * is verified. They are by default; pass false to test the pending and failed paths.
     */
    public function dnsPublished(bool $published = true): static
    {
        $this->dnsPublished = $published;

        return $this;
    }

    /** Back to answering. */
    public function recover(): static
    {
        $this->failure = null;

        return $this;
    }

    /**
     * The stored projects, in the order they were first put.
     *
     * @return list<Project>
     */
    public function projects(): array
    {
        return array_values($this->projects);
    }

    /** A stored project by external ref or id, without recording a call. */
    public function project(string $ref): ?Project
    {
        return array_find($this->projects, static fn (Project $project): bool => $project->is($ref));
    }

    /**
     * The calls received, in order; only those of one operation when given.
     *
     * @return list<RecordedCall>
     */
    public function calls(?Operation $operation = null): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (RecordedCall $call): bool => $operation === null || $call->operation === $operation,
        ));
    }

    /**
     * The puts received for one external ref.
     *
     * @return list<RecordedCall>
     */
    public function puts(string $externalRef): array
    {
        return array_values(array_filter(
            $this->calls(Operation::PutProject),
            static fn (RecordedCall $call): bool => $call->ref === $externalRef,
        ));
    }

    /**
     * @param  (callable(RecordedCall): bool)|null  $where
     */
    public function assertProjectPut(string $externalRef, ?callable $where = null): void
    {
        $matching = array_filter($this->puts($externalRef), static fn (RecordedCall $call): bool => $where === null || $where($call));

        Assert::assertNotEmpty($matching, $where === null
            ? "The project '{$externalRef}' was not put to Cbox Relay."
            : "No put of the project '{$externalRef}' matching the condition reached Cbox Relay.");
    }

    public function assertProjectPutTimes(string $externalRef, int $times): void
    {
        Assert::assertCount($times, $this->puts($externalRef), "The project '{$externalRef}' was not put to Cbox Relay {$times} time(s).");
    }

    public function assertProjectNotPut(string $externalRef): void
    {
        Assert::assertSame([], $this->puts($externalRef), "The project '{$externalRef}' was put to Cbox Relay.");
    }

    /** No project was put: nothing was written, whatever was read. */
    public function assertNoProjectPut(): void
    {
        Assert::assertSame([], $this->describe($this->calls(Operation::PutProject)), 'Projects were put to Cbox Relay.');
    }

    /** At least one call acted for that project (sent it as `Relay-Project`). */
    public function assertActedForProject(string $ref): void
    {
        $matching = array_filter($this->calls, static fn (RecordedCall $call): bool => $call->actingProject === $ref);

        Assert::assertNotEmpty($matching, "No call to Cbox Relay acted for the project '{$ref}'.");
    }

    /**
     * The delivery log was read (with a call matching `$where`).
     *
     * @param  (callable(RecordedCall): bool)|null  $where
     */
    public function assertDeliveriesRead(?callable $where = null): void
    {
        $matching = array_filter($this->calls(Operation::ListDeliveries), static fn (RecordedCall $call): bool => $where === null || $where($call));

        Assert::assertNotEmpty($matching, $where === null
            ? 'The delivery log was not read from Cbox Relay.'
            : 'No read of the delivery log matching the condition reached Cbox Relay.');
    }

    /** Exactly this many calls of that operation were made. */
    public function assertCalledTimes(Operation $operation, int $times): void
    {
        Assert::assertCount($times, $this->calls($operation), "Cbox Relay did not receive {$times} '{$operation->value}' call(s).");
    }

    /** No call at all reached Relay: no read, no write. */
    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->describe($this->calls), 'Calls were sent to Cbox Relay.');
    }

    private function record(RecordedCall $call): void
    {
        $this->calls[] = $call;

        if ($this->failure !== null) {
            throw $this->failure;
        }
    }

    /** The project a call acts for; a `Relay-Project` that names no stored project is a 404. */
    private function acting(?string $project): ?Project
    {
        if ($project === null || $project === '') {
            return null;
        }

        return $this->project($project)
            ?? ($this->anyProject ? $this->seedProject($project) : throw $this->missing('project_not_found', "No project '{$project}' to act for."));
    }

    /** The `external_ref` of the project a call without a `Relay-Project` acts for, as on Relay. */
    public const string DEFAULT_PROJECT = 'default';

    /**
     * Where a scoped call keeps its things: the id of the project it acts for. A call that
     * names none acts for the project `default`, made on first use as Relay makes it.
     */
    private function bucket(?string $project): string
    {
        return ($this->acting($project) ?? $this->project(self::DEFAULT_PROJECT) ?? $this->seedProject(self::DEFAULT_PROJECT, 'Default'))->id;
    }

    /** The same place, for the fake's own helpers: no 404, an unknown project has nothing. */
    private function bucketOf(?string $project): string
    {
        if ($project === null || $project === '') {
            return ($this->project(self::DEFAULT_PROJECT) ?? $this->seedProject(self::DEFAULT_PROJECT, 'Default'))->id;
        }

        return $this->project($project)->id ?? $project;
    }

    private function nextNumber(): int
    {
        return ++$this->sequence;
    }

    private function now(): DateTimeImmutable
    {
        return Carbon::now()->toDateTimeImmutable();
    }

    private function fingerprint(Operation $operation, string $bucket, mixed $input): string
    {
        return $operation->value.'|'.$bucket.'|'.serialize($input);
    }

    /**
     * The first answer to this idempotency key, when the same request is repeated; null
     * for a key that is new (or absent). The same key for a different request is a 409.
     */
    private function replayed(?string $idempotencyKey, string $fingerprint): ?object
    {
        if ($idempotencyKey === null) {
            return null;
        }

        if (($this->idempotencyKeys[$idempotencyKey] ??= $fingerprint) !== $fingerprint) {
            throw new RelayConflict(problem: new Problem(409, 'Conflict', 'The Idempotency-Key was already used for a different request.', self::PROBLEMS.'idempotency_key_reused'));
        }

        return $this->idempotentAnswers[$idempotencyKey] ?? null;
    }

    /**
     * @template TAnswer of object
     *
     * @param  TAnswer  $answer
     * @return TAnswer
     */
    private function remember(?string $idempotencyKey, string $fingerprint, object $answer): object
    {
        if ($idempotencyKey !== null) {
            $this->idempotencyKeys[$idempotencyKey] = $fingerprint;
            $this->idempotentAnswers[$idempotencyKey] = $answer;
        }

        return $answer;
    }

    private function missing(string $type, string $detail): RelayNotFound
    {
        return new RelayNotFound('Cbox Relay answered 404 Not found: '.$detail, new Problem(404, 'Not found', $detail, self::PROBLEMS.$type));
    }

    /**
     * @template TItem
     *
     * @param  list<TItem>  $items
     * @return Page<TItem>
     */
    private function page(array $items, ?int $limit, ?string $cursor): Page
    {
        $limit ??= self::DEFAULT_LIMIT;

        if ($limit < 1) {
            throw $this->invalid('limit', 'The limit must be at least 1.');
        }

        $offset = 0;

        if ($cursor !== null) {
            if (preg_match('/^fake_(\d+)$/', $cursor, $found) !== 1) {
                throw $this->invalid('cursor', 'The cursor is not one this fake handed out.');
            }

            $offset = (int) $found[1];
        }

        $next = $offset + $limit;

        return new Page(
            array_slice($items, $offset, $limit),
            $next < count($items) ? 'fake_'.$next : null,
        );
    }

    private function invalid(string $field, string $message): RelayInvalid
    {
        return new RelayInvalid('Cbox Relay answered 422 Unprocessable Content: '.$message, [$field => [$message]], new Problem(422, 'Unprocessable Content', $message, self::PROBLEMS.'invalid_request', [$field => [$message]]));
    }

    /**
     * @param  list<RecordedCall>  $calls
     * @return list<string>
     */
    private function describe(array $calls): array
    {
        return array_map(
            static fn (RecordedCall $call): string => $call->operation->value.($call->ref === null ? '' : ' '.$call->ref),
            $calls,
        );
    }
}
