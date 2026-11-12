<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Health;

use Cbox\LaravelHealth\Contracts\HealthCheck;
use Cbox\LaravelHealth\DataTransferObjects\CheckResult;
use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Relay;
use Cbox\Relay\Client\RelayConfig;

/**
 * Optional cboxdk/laravel-health check: asks Relay who each configured connection's key
 * is. Only usable when the host app installs cboxdk/laravel-health and lists this class
 * in its `health.checks` config; this package does not require the health package.
 *
 * It is a call to another service: put it on the health endpoint a monitor reads, not on
 * a liveness probe that restarts the app when Relay is slow.
 */
class RelayConnectionCheck implements HealthCheck
{
    public function __construct(
        private readonly Relay $relay,
        private readonly RelayConfig $config,
    ) {}

    public function name(): string
    {
        return 'relay';
    }

    public function run(): CheckResult
    {
        $metadata = [];
        $failures = [];

        foreach ($this->config->connectionNames() as $name) {
            try {
                $client = $name === RelayConfig::DEFAULT_CONNECTION ? $this->relay : $this->relay->connection($name);
                $started = hrtime(true);
                $identity = $client->withoutProject()->whoami();
            } catch (RelayException $e) {
                $failures[] = "{$name}: {$e->getMessage()}";
                $metadata[$name] = ['ok' => false, 'error' => $e->getMessage()];

                continue;
            }

            $metadata[$name] = [
                'ok' => true,
                'rtt_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                'environment' => $identity->environment->value,
            ];
        }

        return $failures === []
            ? CheckResult::ok($this->name(), 'Relay answers for every connection', $metadata)
            : CheckResult::critical($this->name(), implode('; ', $failures), $metadata);
    }
}
