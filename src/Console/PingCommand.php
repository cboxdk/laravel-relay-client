<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Console;

use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Relay;
use Cbox\Relay\Client\RelayConfig;
use Illuminate\Console\Command;

class PingCommand extends Command
{
    protected $signature = 'relay:ping {connection? : A connection from relay.connections; the default when left out}';

    protected $description = 'Ask Cbox Relay who this key is, and time the answer';

    public function handle(Relay $relay): int
    {
        $name = $this->argument('connection');
        $name = is_string($name) && $name !== '' ? $name : RelayConfig::DEFAULT_CONNECTION;

        try {
            $client = $name === RelayConfig::DEFAULT_CONNECTION ? $relay : $relay->connection($name);
            $started = hrtime(true);
            $identity = $client->withoutProject()->whoami();
            $ms = (int) round((hrtime(true) - $started) / 1_000_000);
        } catch (RelayException $e) {
            $this->components->error("{$name}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info("{$name}: Relay answered in {$ms} ms.");
        $this->components->twoColumnDetail('Organization', $identity->organization->name);
        $this->components->twoColumnDetail('Environment', $identity->environment->value);
        $this->components->twoColumnDetail('Key', "{$identity->key->name} ({$identity->key->prefix}…)");
        $this->components->twoColumnDetail('Locked to project', $identity->project === null ? 'no, an organization key' : $identity->project->externalRef);

        return self::SUCCESS;
    }
}
