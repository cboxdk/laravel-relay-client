<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Facades;

use Cbox\Relay\Client\Contracts\RelayTransport;
use Cbox\Relay\Client\Relay as RelayManager;
use Cbox\Relay\Client\Testing\FakeRelay;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use LogicException;

/**
 * @method static \Cbox\Relay\Client\Data\Identity whoami()
 * @method static \Cbox\Relay\Client\Scopes scopes()
 * @method static \Cbox\Relay\Client\Data\Page<\Cbox\Relay\Client\Data\Delivery> deliveries(?\Cbox\Relay\Client\Data\DeliveryFilter $filter = null, ?int $limit = null, ?string $cursor = null)
 * @method static \Cbox\Relay\Client\Relay forScope(string $ref)
 * @method static \Cbox\Relay\Client\Relay withoutScope()
 * @method static ?string scope()
 * @method static \Cbox\Relay\Client\Contracts\RelayTransport transport()
 *
 * @see RelayManager
 */
class Relay extends Facade
{
    /**
     * Swap Cbox Relay for a {@see FakeRelay}: bound as the transport contract, so the
     * facade, an injected manager and an injected contract all talk to the same fake.
     * Nothing leaves the process.
     */
    public static function fake(?FakeRelay $fake = null): FakeRelay
    {
        $app = static::getFacadeApplication();

        if (! $app instanceof Application) {
            throw new LogicException('Relay::fake() needs a booted application; use it from a Laravel or Testbench test case.');
        }

        $fake ??= new FakeRelay;

        $app->instance(RelayTransport::class, $fake);
        static::clearResolvedInstance(RelayManager::class);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return RelayManager::class;
    }
}
