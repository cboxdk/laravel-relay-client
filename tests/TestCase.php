<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Tests;

use Cbox\Relay\Client\RelayServiceProvider;
use Cbox\Relay\Client\Testing\InteractsWithRelay;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use InteractsWithRelay;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RelayServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('relay.url', 'https://relay.test');
        $config->set('relay.key', 'rlk_test_0123456789abcdefghijABCDEFGHIJ0123456789');
        $config->set('relay.scope', null);
    }
}
