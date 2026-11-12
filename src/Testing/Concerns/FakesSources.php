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
    /** @var array<string, array<string, Source>> by scope, then id */
    private array $sources = [];

    /** @var array<string, string> source id => the secret it verifies with now; never given back by a call */
    private array $sourceSecrets = [];

    public function listSources(?string $scope = null): array
    {
        $this->record(new RecordedCall(Operation::ListSources, $scope));

        return array_values($this->sources[$this->bucket($scope)] ?? []);
    }

    public function createSource(string $name, SourceScheme $scheme, #[SensitiveParameter] string $secret, ?string $idempotencyKey = null, ?string $scope = null): Source
    {
        // The secret is not recorded: a recorded call can end up in a failure message.
        $this->record(new RecordedCall(Operation::CreateSource, $scope, $name, $scheme->value, idempotencyKey: $idempotencyKey));
        $bucket = $this->bucket($scope);
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
            throw $this->invalid('name', "This scope has a source named '{$name}' already.");
        }

        $id = 'src_fake_'.$this->nextNumber();
        $source = new Source($id, $name, $scheme->value, 'https://relay.test/fake/in/'.$id, 'inbound.'.$name, 'active', $this->now());

        $this->sources[$bucket][$id] = $source;
        $this->sourceSecrets[$id] = $secret;

        return $this->remember($idempotencyKey, $fingerprint, $source);
    }

    public function findSource(string $id, ?string $scope = null): ?Source
    {
        $this->record(new RecordedCall(Operation::FindSource, $scope, $id));

        return $this->sources[$this->bucket($scope)][$id] ?? null;
    }

    public function rotateSourceSecret(string $id, #[SensitiveParameter] string $secret, ?string $scope = null): Source
    {
        $this->record(new RecordedCall(Operation::RotateSourceSecret, $scope, $id));
        $bucket = $this->bucket($scope);
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

    public function pauseSource(string $id, ?string $scope = null): Source
    {
        $this->record(new RecordedCall(Operation::PauseSource, $scope, $id));

        return $this->sourceStatus($this->bucket($scope), $id, 'paused');
    }

    public function resumeSource(string $id, ?string $scope = null): Source
    {
        $this->record(new RecordedCall(Operation::ResumeSource, $scope, $id));

        return $this->sourceStatus($this->bucket($scope), $id, 'active');
    }

    public function deleteSource(string $id, ?string $scope = null): void
    {
        $this->record(new RecordedCall(Operation::DeleteSource, $scope, $id));
        $bucket = $this->bucket($scope);
        $this->sourceIn($bucket, $id);

        unset($this->sources[$bucket][$id], $this->sourceSecrets[$id]);
    }

    /**
     * Store a source for a scope (stored first if it is not there) as if it had been
     * created earlier, without recording a call.
     */
    public function seedSource(string $scope, string $name, SourceScheme $scheme = SourceScheme::StandardWebhooks, #[SensitiveParameter] string $secret = 'fake_provider_secret', string $status = 'active'): Source
    {
        $bucket = ($this->scope($scope) ?? $this->seedScope($scope))->id;
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
        return $this->sources[$bucket][$id] ?? throw $this->missing('source_not_found', 'No source with that id exists in this scope.');
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
