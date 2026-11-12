<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/** 409: the Idempotency-Key was already used for a different request. */
class RelayConflict extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay refused the request: the idempotency key was used for a different request.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
