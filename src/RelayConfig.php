<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Illuminate\Contracts\Config\Repository;

/**
 * Where Cbox Relay is and which key this app uses, read from `config/relay.php`.
 * Without both nothing in this package talks to Relay.
 */
class RelayConfig
{
    public function __construct(private readonly Repository $config) {}

    public function configured(): bool
    {
        return $this->url() !== '' && $this->key() !== '';
    }

    /** The API, without a trailing slash; empty when unconfigured. */
    public function url(): string
    {
        return rtrim($this->string('relay.url'), '/');
    }

    /** The API key; empty when unconfigured. Never log it. */
    public function key(): string
    {
        return $this->string('relay.key');
    }

    /** Seconds a whole call may take. */
    public function timeout(): float
    {
        return $this->seconds('relay.timeout', 5.0);
    }

    /** Seconds connecting may take. */
    public function connectTimeout(): float
    {
        return $this->seconds('relay.connect_timeout', 2.0);
    }

    /** The scope every call acts for unless told otherwise; null when none is set. */
    public function scope(): ?string
    {
        $scope = $this->string('relay.scope');

        return $scope === '' ? null : $scope;
    }

    /** Whether the key is a scope key: locked to one scope, so calls need no `Relay-Scope`. */
    public function scopeKey(): bool
    {
        return $this->flag('relay.scope_key');
    }

    /** Whether `Relay::publish()` calls Relay in the caller's process instead of from the queue. */
    public function publishSync(): bool
    {
        return $this->flag('relay.publish.sync');
    }

    /** The queue connection the publish job goes on; null for the app's default. */
    public function publishConnection(): ?string
    {
        $connection = $this->string('relay.publish.connection');

        return $connection === '' ? null : $connection;
    }

    /** The queue the publish job goes on; null for the connection's default. */
    public function publishQueue(): ?string
    {
        $queue = $this->string('relay.publish.queue');

        return $queue === '' ? null : $queue;
    }

    /** How often the publish job is attempted in all. */
    public function publishTries(): int
    {
        $tries = $this->config->get('relay.publish.tries');

        return is_numeric($tries) && (int) $tries > 0 ? (int) $tries : 5;
    }

    /**
     * Seconds before the second attempt, the third, and so on.
     *
     * @return list<int>
     */
    public function publishBackoff(): array
    {
        $backoff = $this->config->get('relay.publish.backoff');

        if (is_string($backoff)) {
            $backoff = explode(',', $backoff);
        }

        $seconds = [];

        foreach (is_array($backoff) ? $backoff : [] as $value) {
            if (is_numeric($value) && (int) $value >= 0) {
                $seconds[] = (int) $value;
            }
        }

        return $seconds === [] ? [10, 30, 120, 600] : $seconds;
    }

    /**
     * The secrets a webhook from Relay may be signed with: the current one and, while a
     * rotation is under way, the previous one. Empty when none is configured.
     *
     * @return list<string>
     */
    public function webhookSecrets(): array
    {
        return array_values(array_filter(
            [$this->string('relay.webhook.secret'), $this->string('relay.webhook.previous_secret')],
            static fn (string $secret): bool => $secret !== '',
        ));
    }

    /** Seconds a webhook's timestamp may be off, either way. */
    public function webhookTolerance(): int
    {
        $tolerance = $this->config->get('relay.webhook.tolerance');

        return is_numeric($tolerance) && (int) $tolerance > 0 ? (int) $tolerance : 300;
    }

    private function flag(string $key): bool
    {
        return filter_var($this->config->get($key), FILTER_VALIDATE_BOOLEAN);
    }

    private function seconds(string $key, float $default): float
    {
        $value = $this->config->get($key);

        return is_numeric($value) && (float) $value > 0 ? (float) $value : $default;
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }
}
