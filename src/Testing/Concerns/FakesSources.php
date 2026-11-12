<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing\Concerns;

use Cbox\Relay\Client\Data\Source;
use Cbox\Relay\Client\Data\SourceScheme;
use Cbox\Relay\Client\Testing\Operation;
use Cbox\Relay\Client\Testing\RecordedCall;
use PHPUnit\Framework\Assert;
use SensitiveParameter;

/** Inbound webhook sources, in memory: sources are stored, no provider ever calls one. */
trait FakesSources
{
    /** @var array<string, array<string, Source>> by project, then id */
    private array $sources = [];

    /** @var array<string, string> source id => the secret it verifies with now; never given back by a call */
    private array $sourceSecrets = [];

    public function listSources(?string $project = null): array
    {
        $this->record(new RecordedCall(Operation::ListSources, $project));

        return array_values($this->sources[$this->bucket($project)] ?? []);
    }

    public function createSource(string $name, SourceScheme $scheme, #[SensitiveParameter] string $secret, ?string $idempotencyKey = null, ?string $project = null): Source
    {
        // The secret is not recorded: a recorded call can end up in a failure message.
        $this->record(new RecordedCall(Operation::CreateSource, $project, $name, $scheme->value, idempotencyKey: $idempotencyKey));
        $bucket = $this->bucket($project);
        $fingerprint = $this->fingerprint(Operation::CreateSource, $bucket, [$name, $scheme, hash('sha256', $secret)]);
        $replayed = $this->replayed($idempotencyKey, $fingerprint);

        if ($replayed instanceof Source) {
            return $replayed;
        }

        if (preg_match('/\A[a-z0-9]([a-z0-9-]{0,38}[a-z0-9])?\z/', $name) !== 1) {
            throw $this->invalid('name', 'A source name is 1 to 40 lower-case letters, digits or hyphens, not starting or ending with a hyphen.');
        }

        if ($secret === '' || strlen($secret) > 500) {
            throw $this->invalid('secret', 'A secret is 1 to 500 characters.');
        }

        if (array_find($this->sources[$bucket] ?? [], static fn (Source $source): bool => $source->name === $name) !== null) {
            throw $this->invalid('name', "This project has a source named '{$name}' already.");
        }

        $id = 'src_fake_'.$this->nextNumber();
        $source = new Source($id, $name, $scheme->value, 'https://relay.test/fake/in/'.$id, 'inbound.'.$name, 'active', $this->now());

        $this->sources[$bucket][$id] = $source;
        $this->sourceSecrets[$id] = $secret;

        return $this->remember($idempotencyKey, $fingerprint, $source);
    }

    public function findSource(string $id, ?string $project = null): ?Source
    {
        $this->record(new RecordedCall(Operation::FindSource, $project, $id));

        return $this->sources[$this->bucket($project)][$id] ?? null;
    }

    public function rotateSourceSecret(string $id, #[SensitiveParameter] string $secret, ?string $project = null): Source
    {
        $this->record(new RecordedCall(Operation::RotateSourceSecret, $project, $id));
        $bucket = $this->bucket($project);
        $source = $this->sourceIn($bucket, $id);

        if ($secret === '' || strlen($secret) > 500) {
            throw $this->invalid('secret', 'A secret is 1 to 500 characters.');
        }

        $this->sourceSecrets[$id] = $secret;

        return $this->sources[$bucket][$id] = new Source(
            $source->id, $source->name, $source->scheme, $source->url, $source->eventType, $source->status, $source->createdAt,
            $this->now()->modify('+24 hours'), $source->lastReceivedAt,
        );
    }

    public function pauseSource(string $id, ?string $project = null): Source
    {
        $this->record(new RecordedCall(Operation::PauseSource, $project, $id));

        return $this->sourceStatus($this->bucket($project), $id, 'paused');
    }

    public function resumeSource(string $id, ?string $project = null): Source
    {
        $this->record(new RecordedCall(Operation::ResumeSource, $project, $id));

        return $this->sourceStatus($this->bucket($project), $id, 'active');
    }

    public function deleteSource(string $id, ?string $project = null): void
    {
        $this->record(new RecordedCall(Operation::DeleteSource, $project, $id));
        $bucket = $this->bucket($project);
        $this->sourceIn($bucket, $id);

        unset($this->sources[$bucket][$id], $this->sourceSecrets[$id]);
    }

    /**
     * Store a source for a project (stored first if it is not there) as if it had been
     * created earlier, without recording a call.
     */
    public function seedSource(string $project, string $name, SourceScheme $scheme = SourceScheme::StandardWebhooks, #[SensitiveParameter] string $secret = 'fake_provider_secret', string $status = 'active'): Source
    {
        $bucket = ($this->project($project) ?? $this->seedProject($project))->id;
        $id = 'src_fake_'.$this->nextNumber();

        $this->sourceSecrets[$id] = $secret;

        return $this->sources[$bucket][$id] = new Source($id, $name, $scheme->value, 'https://relay.test/fake/in/'.$id, 'inbound.'.$name, $status, $this->now());
    }

    /**
     * A source with that name exists (and matches `$where`).
     *
     * @param  (callable(Source, string): bool)|null  $where  given the source and the secret it verifies with now
     */
    public function assertSourceRegistered(string $name, ?callable $where = null): void
    {
        $matching = [];

        foreach ($this->sources as $sources) {
            foreach ($sources as $source) {
                if ($source->name === $name && ($where === null || $where($source, $this->sourceSecrets[$source->id] ?? ''))) {
                    $matching[] = $source;
                }
            }
        }

        Assert::assertNotEmpty($matching, "No source '{$name}'".($where === null ? '' : ' matching the condition').' is registered with Cbox Relay.');
    }

    public function assertNoSourceRegistered(): void
    {
        Assert::assertSame([], array_merge(...array_values(array_map(array_keys(...), $this->sources))), 'Sources are registered with Cbox Relay.');
    }

    private function sourceIn(string $bucket, string $id): Source
    {
        return $this->sources[$bucket][$id] ?? throw $this->missing('source_not_found', 'No source with that id exists in this project.');
    }

    private function sourceStatus(string $bucket, string $id, string $status): Source
    {
        $source = $this->sourceIn($bucket, $id);

        return $this->sources[$bucket][$id] = new Source(
            $source->id, $source->name, $source->scheme, $source->url, $source->eventType, $status, $source->createdAt,
            $source->previousSecretExpiresAt, $source->lastReceivedAt,
        );
    }
}
