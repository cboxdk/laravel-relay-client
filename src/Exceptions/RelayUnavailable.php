<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/**
 * Relay could not be reached or answered 5xx: a timeout, a refused connection, an
 * outage. The same call may well succeed later; the client does not retry on its own.
 */
class RelayUnavailable extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay is unavailable.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
