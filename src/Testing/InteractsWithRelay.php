<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

use Cbox\Relay\Client\Facades\Relay;

/**
 * Swap Cbox Relay for {@see FakeRelay} in a Laravel or Testbench test case: scopes are
 * kept in memory, deliveries are whatever the test seeded, nothing leaves the process.
 */
trait InteractsWithRelay
{
    protected function fakeRelay(?FakeRelay $fake = null): FakeRelay
    {
        return Relay::fake($fake);
    }
}
