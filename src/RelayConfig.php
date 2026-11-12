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
