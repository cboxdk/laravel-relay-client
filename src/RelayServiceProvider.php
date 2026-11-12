<?php

declare(strict_types=1);

namespace Cbox\Relay\Client;

use Cbox\Relay\Client\Console\DoctorCommand;
use Cbox\Relay\Client\Console\InstallCommand;
use Cbox\Relay\Client\Console\PingCommand;
use Cbox\Relay\Client\Console\TailCommand;
use Cbox\Relay\Client\Contracts\EventPublisher;
use Cbox\Relay\Client\Contracts\MessageStore;
use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Contracts\WebhookSecrets;
use Cbox\Relay\Client\Http\HttpRelayTransport;
use Cbox\Relay\Client\Http\Middleware\VerifyRelayWebhook;
use Cbox\Relay\Client\Listeners\DescribeSentMail;
use Cbox\Relay\Client\Mail\RelayMailTransport;
use Cbox\Relay\Client\Publishing\ImmediateEventPublisher;
use Cbox\Relay\Client\Publishing\QueuedEventPublisher;
use Cbox\Relay\Client\Store\DatabaseMessageStore;
use Cbox\Relay\Client\Store\NullMessageStore;
use Cbox\Relay\Client\Support\Doctor;
use Cbox\Relay\Client\Webhooks\ConfiguredWebhookSecrets;
use Cbox\Relay\Client\Webhooks\WebhookRoutes;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\MailManager;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the transport contract to its HTTP implementation, the publisher to the queue
 * (or to an immediate one with `relay.publish.sync`) and the {@see Relay} manager on top
 * of whatever is bound, acting for `relay.project` when one is set. Registers the `relay`
 * mail transport, the `relay.webhook` route middleware, the commands, the optional
 * webhook route and the optional local store.
 *
 * A named connection (`relay.connections`) gets a transport of its own: the one bound as
 * `relay.transport.{name}` when the app bound one, else the HTTP transport with that
 * connection's key. When {@see RelayTransport} itself is bound to something else (the
 * fake, a decorator), every connection goes through that.
 */
class RelayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/relay.php', 'relay');

        $this->app->singleton(RelayConfig::class);

        $this->app->bind(RelayTransport::class, HttpRelayTransport::class);
        $this->app->bind(WebhookSecrets::class, ConfiguredWebhookSecrets::class);

        $this->app->bind(EventPublisher::class, static fn (Application $app): EventPublisher => $app->make(RelayConfig::class)->publishSync()
            ? new ImmediateEventPublisher($app->make(RelayTransport::class))
            : new QueuedEventPublisher($app->make(Dispatcher::class), $app->make(RelayConfig::class)));

        // Keeps nothing, and asks for no database, until `relay.store.enabled` says so.
        $this->app->bind(MessageStore::class, static fn (Application $app): MessageStore => $app->make(RelayConfig::class)->storeEnabled()
            ? new DatabaseMessageStore($app->make(RelayConfig::class))
            : new NullMessageStore);

        // Not a singleton: the manager is a few references, and building it per resolution
        // means a transport bound later (a fake, a decorator) is the one that is used.
        $this->app->bind(Relay::class, static fn (Application $app): Relay => self::client($app, null));

        $this->app->bind(Doctor::class, static fn (Application $app): Doctor => new Doctor(
            $app->make(RelayConfig::class),
            $app->make(Relay::class),
            $app->make(Router::class),
            $app->make(Repository::class),
            $app->bound('db') ? $app->make(DatabaseManager::class) : null,
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/relay.php' => $this->app->configPath('relay.php')], 'relay-config');
            // Published, never loaded: the tables exist only in an app that asked for the store.
            $this->publishes([__DIR__.'/../database/migrations/create_relay_messages_tables.php.stub' => $this->app->databasePath('migrations/'.date('Y_m_d_His').'_create_relay_messages_tables.php')], 'relay-migrations');
            $this->commands([InstallCommand::class, DoctorCommand::class, PingCommand::class, TailCommand::class]);
        }

        $this->callAfterResolving('mail.manager', function (MailManager $mail): void {
            // `MAIL_MAILER=relay` with `'relay' => ['transport' => 'relay']` in config/mail.php.
            $mail->extend('relay', function (array $config = []): RelayMailTransport {
                $project = $config['project'] ?? null;
                $connection = $config['connection'] ?? null;

                return new RelayMailTransport(
                    fn (): Relay => $this->app->make(Relay::class),
                    is_string($project) && $project !== '' ? $project : null,
                    is_string($connection) && $connection !== '' && $connection !== RelayConfig::DEFAULT_CONNECTION ? $connection : null,
                );
            });
        });

        $this->callAfterResolving('router', static function (Router $router): void {
            $router->aliasMiddleware('relay.webhook', VerifyRelayWebhook::class);
        });

        $config = $this->app->make(RelayConfig::class);
        $route = $config->webhookRoute();

        if ($route !== null && ! $this->app->routesAreCached()) {
            WebhookRoutes::register($this->app->make(Router::class), $route);
        }

        if ($config->storeEnabled()) {
            $this->app->make(Events::class)->listen(MessageSent::class, DescribeSentMail::class);
        }
    }

    /** The client of one connection; null is the default. */
    private static function client(Application $app, ?string $name): Relay
    {
        $config = $app->make(RelayConfig::class);
        $name = $name === RelayConfig::DEFAULT_CONNECTION ? null : $name;
        $connection = $config->connection($name);
        $transport = self::transport($app, $name);

        return new Relay(
            $transport,
            $connection->project,
            match (true) {
                $name === null => $app->make(EventPublisher::class),
                // A fake or a decorator publishes the way the default does; the queue otherwise, unless publishing is immediate.
                $config->publishSync() || ! $transport instanceof HttpRelayTransport => new ImmediateEventPublisher($transport),
                default => new QueuedEventPublisher($app->make(Dispatcher::class), $config, $name),
            },
            $connection->projectKey,
            static fn (string $other): Relay => self::client($app, $other),
            $app->make(MessageStore::class),
            $name,
        );
    }

    private static function transport(Application $app, ?string $name): RelayTransport
    {
        $bound = $app->make(RelayTransport::class);

        if ($name === null) {
            return $bound;
        }

        if ($app->bound("relay.transport.{$name}")) {
            $own = $app->make("relay.transport.{$name}");

            if ($own instanceof RelayTransport) {
                return $own;
            }
        }

        return $bound instanceof HttpRelayTransport
            ? new HttpRelayTransport($app->make(RelayConfig::class), $app->make(Factory::class), $name)
            : $bound;
    }
}
