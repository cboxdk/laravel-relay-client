<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'relay:install
        {--store : Also publish the migration of the optional local delivery status tables}
        {--force : Overwrite files that were published before}';

    protected $description = 'Publish the Cbox Relay config (and, with --store, its migration) and say what to set';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->call('vendor:publish', ['--tag' => 'relay-config', '--force' => $force]);

        if ((bool) $this->option('store')) {
            $this->call('vendor:publish', ['--tag' => 'relay-migrations', '--force' => $force]);
        }

        $this->newLine();
        $this->line('  Set these in .env:');
        $this->newLine();

        foreach ([
            'RELAY_URL' => 'where Relay\'s API is, e.g. https://api.cboxrelay.com',
            'RELAY_KEY' => 'an API key from Relay\'s console (rlk_live_… or rlk_test_…)',
            'RELAY_PROJECT' => 'optional: the project an organization key acts for by default',
            'RELAY_WEBHOOK_SECRET' => 'the signing secret of the endpoint Relay sends this app\'s webhooks to',
        ] as $key => $what) {
            $this->components->twoColumnDetail($key, $what);
        }

        $this->newLine();
        $this->line('  To send Laravel mail through Relay, add to config/mail.php: <comment>\'relay\' => [\'transport\' => \'relay\']</comment> and set MAIL_MAILER=relay.');
        $this->line('  To receive Relay\'s webhooks as Laravel events, call <comment>Relay::webhookRoutes()</comment> in a routes file.');

        if ((bool) $this->option('store')) {
            $this->line('  The store: set <comment>RELAY_STORE=true</comment>, then run <comment>php artisan migrate</comment>.');
        }

        $this->newLine();
        $this->components->info('Then run php artisan relay:doctor.');

        return self::SUCCESS;
    }
}
