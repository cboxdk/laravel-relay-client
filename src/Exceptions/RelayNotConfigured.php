<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/** `relay.url` or `relay.key` is empty, so nothing was sent. */
class RelayNotConfigured extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay is not configured: set RELAY_URL and RELAY_KEY.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
