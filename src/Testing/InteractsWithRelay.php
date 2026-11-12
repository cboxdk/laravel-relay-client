<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

use Cbox\Relay\Client\Facades\Relay;

/**
 * Swap Cbox Relay for {@see FakeRelay} in a Laravel or Testbench test case: everything
 * is kept in memory, `Relay::publish()` records instead of queueing, and nothing leaves
 * the process.
 */
trait InteractsWithRelay
{
    protected function fakeRelay(?FakeRelay $fake = null): FakeRelay
    {
        return Relay::fake($fake);
    }
}
