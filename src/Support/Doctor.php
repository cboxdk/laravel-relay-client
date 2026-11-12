<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Support;

use Cbox\Relay\Client\Exceptions\RelayException;
use Cbox\Relay\Client\Models\RelayMessage;
use Cbox\Relay\Client\Models\RelayMessageEvent;
use Cbox\Relay\Client\Relay;
use Cbox\Relay\Client\RelayConfig;
use Cbox\Relay\Client\Webhooks\WebhookRoutes;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Throwable;

/**
 * Looks at this app's Relay setup the way `relay:doctor` reports it: every connection's
 * key and project, whether Relay answers, the webhook secret and route, the store's
 * tables and the mailer. Nothing here prints a key or a secret.
 */
class Doctor
{
    public function __construct(
        private readonly RelayConfig $config,
        private readonly Relay $relay,
        private readonly Router $router,
        private readonly Repository $settings,
        private readonly ?DatabaseManager $database = null,
    ) {}

    /**
     * @param  bool  $ping  call Relay (`whoami`, and the project when one is named); false looks at config only
     * @return list<DoctorCheck>
     */
    public function run(bool $ping = true): array
    {
        $checks = [];

        foreach ($this->config->connectionNames() as $name) {
            $checks = [...$checks, ...$this->connection($name, $ping)];
        }

        return [...$checks, ...$this->webhooks(), ...$this->store(), ...$this->mailer()];
    }

    /** @return list<DoctorCheck> */
    private function connection(string $name, bool $ping): array
    {
        $section = "connection {$name}";
        $connection = $this->config->connection($name);

        if (! $connection->configured()) {
            return [DoctorCheck::failure($section, 'Key and URL', $name === RelayConfig::DEFAULT_CONNECTION
                ? 'Set RELAY_URL and RELAY_KEY.'
                : "Give relay.connections.{$name} a key (and a url, unless the top level has one).")];
        }

        $environment = $connection->environment();
        $checks = [$environment === null
            ? DoctorCheck::warning($section, 'Key', 'The key does not start with rlk_live_ or rlk_test_: is it a Relay key?')
            : DoctorCheck::ok($section, 'Key', "a {$environment} key, at {$connection->url}")];

        if ($connection->webhookSecrets === []) {
            $checks[] = DoctorCheck::warning($section, 'Webhook secret', 'None set: a webhook from Relay for this connection is refused.');
        } else {
            $checks[] = DoctorCheck::ok($section, 'Webhook secret', count($connection->webhookSecrets) > 1 ? 'set, with the previous one still accepted' : 'set');
        }

        if (! $ping) {
            $checks[] = $connection->project === null && ! $connection->projectKey
                ? DoctorCheck::warning($section, 'Project', 'No default project and not a project key: name a project per call (Relay::forProject()), or set one.')
                : DoctorCheck::ok($section, 'Project', $connection->projectKey ? 'the key is a project key' : "default project {$connection->project}");

            return $checks;
        }

        $relay = $name === RelayConfig::DEFAULT_CONNECTION ? $this->relay : $this->relay->connection($name);

        try {
            $started = hrtime(true);
            $identity = $relay->withoutProject()->whoami();
            $ms = (int) round((hrtime(true) - $started) / 1_000_000);
        } catch (RelayException $e) {
            return [...$checks, DoctorCheck::failure($section, 'Relay answers', $e->getMessage())];
        }

        $checks[] = DoctorCheck::ok($section, 'Relay answers', "{$identity->organization->name}, {$identity->environment->value}, key \"{$identity->key->name}\", {$ms} ms");

        if ($environment !== null && $environment !== $identity->environment->value) {
            $checks[] = DoctorCheck::failure($section, 'Environment', "The key's prefix says {$environment} but Relay says {$identity->environment->value}.");
        }

        return [...$checks, $this->project($section, $relay, $connection, $identity->project?->externalRef)];
    }

    private function project(string $section, Relay $relay, ConnectionConfig $connection, ?string $locked): DoctorCheck
    {
        if ($connection->projectKey) {
            return $locked === null
                ? DoctorCheck::failure($section, 'Project', 'project_key is on, but this key is an organization key: calls that need a project will be refused. Turn it off and name a project.')
                : DoctorCheck::ok($section, 'Project', "the key is locked to {$locked}");
        }

        if ($locked !== null) {
            return DoctorCheck::warning($section, 'Project', "This key is locked to {$locked}: set project_key (RELAY_PROJECT_KEY=true) so calls go out without naming one.");
        }

        if ($connection->project === null) {
            return DoctorCheck::warning($section, 'Project', 'No default project: name a project per call (Relay::forProject()), or set one.');
        }

        try {
            $found = $relay->withoutProject()->projects()->find($connection->project);
        } catch (RelayException $e) {
            return DoctorCheck::failure($section, 'Project', $e->getMessage());
        }

        return $found === null
            ? DoctorCheck::failure($section, 'Project', "Relay has no project {$connection->project} in this environment: create it with Relay::projects()->put().")
            : DoctorCheck::ok($section, 'Project', "default project {$connection->project}");
    }

    /** @return list<DoctorCheck> */
    private function webhooks(): array
    {
        $route = $this->router->getRoutes()->getByName(WebhookRoutes::NAME);

        if ($route === null) {
            return [DoctorCheck::warning('webhooks', 'Route', 'The package\'s webhook route is not registered: call Relay::webhookRoutes() in a routes file or set relay.webhook.route. Fine when you have a route of your own behind relay.webhook.')];
        }

        $checks = [DoctorCheck::ok('webhooks', 'Route', 'POST /'.ltrim($route->uri(), '/'))];

        if ($this->config->webhookSecrets() === []) {
            $checks[] = DoctorCheck::failure('webhooks', 'Secret', 'The route is registered but RELAY_WEBHOOK_SECRET is empty: every delivery answers 500.');
        }

        return $checks;
    }

    /** @return list<DoctorCheck> */
    private function store(): array
    {
        if (! $this->config->storeEnabled()) {
            return [DoctorCheck::ok('store', 'Local delivery status', 'off (relay.store.enabled): nothing is kept here, ask Relay')];
        }

        if ($this->database === null) {
            return [DoctorCheck::failure('store', 'Database', 'relay.store.enabled is on but this app has no database.')];
        }

        $checks = [];

        foreach ([new RelayMessage, new RelayMessageEvent] as $model) {
            try {
                $exists = $this->database->connection($this->config->storeConnection())->getSchemaBuilder()->hasTable($model->getTable());
            } catch (Throwable $e) {
                return [DoctorCheck::failure('store', 'Database', $e->getMessage())];
            }

            $checks[] = $exists
                ? DoctorCheck::ok('store', "Table {$model->getTable()}")
                : DoctorCheck::failure('store', "Table {$model->getTable()}", 'Missing: run php artisan relay:install --store, then php artisan migrate.');
        }

        return $checks;
    }

    /** @return list<DoctorCheck> */
    private function mailer(): array
    {
        $mailers = $this->settings->get('mail.mailers');
        $relay = [];

        foreach (is_array($mailers) ? $mailers : [] as $name => $mailer) {
            if (is_string($name) && is_array($mailer) && ($mailer['transport'] ?? null) === 'relay') {
                $relay[] = $name;
            }
        }

        if ($relay === []) {
            return [DoctorCheck::warning('mail', 'Mailer', "No mailer uses the relay transport: add 'relay' => ['transport' => 'relay'] to config/mail.php to send Laravel mail through Relay.")];
        }

        $default = $this->settings->get('mail.default');

        return [DoctorCheck::ok('mail', 'Mailer', implode(', ', $relay).(is_string($default) && in_array($default, $relay, true) ? ' (the default mailer)' : ' (not the default mailer)'))];
    }
}
