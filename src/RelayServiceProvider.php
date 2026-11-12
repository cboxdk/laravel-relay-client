<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Contracts\EventPublisher;
use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Http\HttpRelayTransport;
use Cbox\Relay\Client\Mail\RelayMailTransport;
use Cbox\Relay\Client\Publishing\ImmediateEventPublisher;
use Cbox\Relay\Client\Publishing\QueuedEventPublisher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the transport contract to its HTTP implementation, the publisher to the queue
 * (or to an immediate one with `relay.publish.sync`) and the {@see Relay} manager on top
 * of whatever is bound, acting for `relay.scope` when one is set. Registers the `relay`
 * mail transport.
 */
class RelayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/relay.php', 'relay');

        $this->app->singleton(RelayConfig::class);

        $this->app->bind(RelayTransport::class, HttpRelayTransport::class);

        $this->app->bind(EventPublisher::class, static fn (Application $app): EventPublisher => $app->make(RelayConfig::class)->publishSync()
            ? new ImmediateEventPublisher($app->make(RelayTransport::class))
            : new QueuedEventPublisher($app->make(Dispatcher::class), $app->make(RelayConfig::class)));

        // Not a singleton: the manager is a few references, and building it per resolution
        // means a transport bound later (a fake, a decorator) is the one that is used.
        $this->app->bind(Relay::class, static fn (Application $app): Relay => new Relay(
            $app->make(RelayTransport::class),
            $app->make(RelayConfig::class)->scope(),
            $app->make(EventPublisher::class),
            $app->make(RelayConfig::class)->scopeKey(),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/relay.php' => $this->app->configPath('relay.php')], 'relay-config');
        }

        $this->callAfterResolving('mail.manager', function (MailManager $mail): void {
            // `MAIL_MAILER=relay` with `'relay' => ['transport' => 'relay']` in config/mail.php.
            $mail->extend('relay', function (array $config = []): RelayMailTransport {
                $scope = $config['scope'] ?? null;

                return new RelayMailTransport(fn (): Relay => $this->app->make(Relay::class), is_string($scope) && $scope !== '' ? $scope : null);
            });
        });
    }
}
