<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/** Which side of Relay a key works on: `rlk_live_…` keys are live, `rlk_test_…` keys test. */
enum Environment: string
{
    case Live = 'live';
    case Test = 'test';
}
