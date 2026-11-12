<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Http\HttpRelayTransport;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the transport contract to its HTTP implementation and the {@see Relay} manager
 * on top of whatever transport is bound, acting for `relay.scope` when one is set.
 */
class RelayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/relay.php', 'relay');

        $this->app->singleton(RelayConfig::class);

        $this->app->bind(RelayTransport::class, HttpRelayTransport::class);

        // Not a singleton: the manager is two references, and building it per resolution
        // means a transport bound later (a fake, a decorator) is the one that is used.
        $this->app->bind(Relay::class, static fn (Application $app): Relay => new Relay(
            $app->make(RelayTransport::class),
            $app->make(RelayConfig::class)->scope(),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/relay.php' => $this->app->configPath('relay.php')], 'relay-config');
        }
    }
}
