<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Exceptions\RelayNotConfigured;
use Cbox\Relay\Client\Support\ConnectionConfig;
use Illuminate\Contracts\Config\Repository;

/**
 * Where Cbox Relay is and which key this app uses, read from `config/relay.php`.
 * Without both nothing in this package talks to Relay.
 */
class RelayConfig
{
    public function __construct(private readonly Repository $config) {}

    /** The name of the connection the top level of `config/relay.php` describes. */
    public const string DEFAULT_CONNECTION = 'default';

    /**
     * One connection by name; null or `default` is the top level of the config. A named
     * connection takes its own key, project and webhook secrets, and falls back to the
     * top level for the URL and the timeouts it does not set.
     *
     * @throws RelayNotConfigured when no connection has that name
     */
    public function connection(?string $name = null): ConnectionConfig
    {
        if ($name === null || $name === self::DEFAULT_CONNECTION) {
            return new ConnectionConfig(self::DEFAULT_CONNECTION, $this->url(), $this->key(), $this->project(), $this->timeout(), $this->connectTimeout(), $this->webhookSecrets());
        }

        $base = "relay.connections.{$name}";

        if (! is_array($this->config->get($base))) {
            throw new RelayNotConfigured("No Relay connection is named '{$name}': add it under relay.connections.");
        }

        $url = rtrim($this->string("{$base}.url"), '/');
        $project = $this->string("{$base}.project");

        return new ConnectionConfig(
            $name,
            $url === '' ? $this->url() : $url,
            $this->string("{$base}.key"),
            $project === '' ? null : $project,
            $this->seconds("{$base}.timeout", $this->timeout()),
            $this->seconds("{$base}.connect_timeout", $this->connectTimeout()),
            array_values(array_filter(
                [$this->string("{$base}.webhook.secret"), $this->string("{$base}.webhook.previous_secret")],
                static fn (string $secret): bool => $secret !== '',
            )),
        );
    }

    /**
     * Every connection's name, `default` first.
     *
     * @return list<string>
     */
    public function connectionNames(): array
    {
        $connections = $this->config->get('relay.connections');
        $names = [self::DEFAULT_CONNECTION];

        foreach (is_array($connections) ? array_keys($connections) : [] as $name) {
            if (is_string($name) && $name !== self::DEFAULT_CONNECTION) {
                $names[] = $name;
            }
        }

        return $names;
    }

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

    /** The project every call acts for unless told otherwise; null when none is set. */
    public function project(): ?string
    {
        $project = $this->string('relay.project');

        return $project === '' ? null : $project;
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

    /** The path of the package's own webhook route (`relay.webhook.route`); null when it is not registered from config. */
    public function webhookRoute(): ?string
    {
        $path = trim($this->string('relay.webhook.route'), '/');

        return $path === '' ? null : $path;
    }

    /** The cache store deliveries are remembered in, so one is handled once; null for the app's default. */
    public function webhookCacheStore(): ?string
    {
        $store = $this->string('relay.webhook.cache');

        return $store === '' ? null : $store;
    }

    /** Seconds a handled delivery is remembered. Relay retries a delivery for about a day. */
    public function webhookDedupeSeconds(): int
    {
        $seconds = $this->config->get('relay.webhook.dedupe');

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : 172800;
    }

    /** Whether sent mail and its events are kept in this app's own database (`relay.store.enabled`). */
    public function storeEnabled(): bool
    {
        return $this->flag('relay.store.enabled');
    }

    /** The database connection the store's tables are on; null for the app's default. */
    public function storeConnection(): ?string
    {
        $connection = $this->string('relay.store.connection');

        return $connection === '' ? null : $connection;
    }

    /** How a mail's subject is kept: `hash` (a SHA-256, the default), `plain`, or `none`. */
    public function storeSubject(): string
    {
        $mode = $this->string('relay.store.subject');

        return in_array($mode, ['plain', 'none'], true) ? $mode : 'hash';
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
