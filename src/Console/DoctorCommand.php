<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Console;

use Cbox\Relay\Client\Support\Doctor;
use Cbox\Relay\Client\Support\DoctorCheck;
use Cbox\Relay\Client\Support\DoctorStatus;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'relay:doctor {--no-ping : Look at the configuration only, without calling Relay}';

    protected $description = 'Check the Cbox Relay setup: keys, projects, reachability, webhook secret and route, the local store and the mailer';

    public function handle(Doctor $doctor): int
    {
        $checks = $doctor->run(ping: ! (bool) $this->option('no-ping'));
        $section = null;

        foreach ($checks as $check) {
            if ($check->section !== $section) {
                $section = $check->section;
                $this->newLine();
                $this->line('  <options=bold>'.ucfirst($section).'</>');
            }

            $this->line('  '.match ($check->status) {
                DoctorStatus::Ok => '<info>✓</info>',
                DoctorStatus::Warning => '<comment>!</comment>',
                DoctorStatus::Failure => '<error>✗</error>',
            }." {$check->name}".($check->message === '' ? '' : ": {$check->message}"));
        }

        $failures = count(array_filter($checks, static fn (DoctorCheck $check): bool => $check->status === DoctorStatus::Failure));
        $warnings = count(array_filter($checks, static fn (DoctorCheck $check): bool => $check->status === DoctorStatus::Warning));

        $this->newLine();

        if ($failures > 0) {
            $this->components->error("{$failures} failed, {$warnings} to look at. Fix what failed and run relay:doctor again.");

            return self::FAILURE;
        }

        $warnings > 0
            ? $this->components->warn("Nothing failed. {$warnings} to look at.")
            : $this->components->info('Everything is in place.');

        return self::SUCCESS;
    }
}
