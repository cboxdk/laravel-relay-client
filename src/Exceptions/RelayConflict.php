<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/**
 * 409: the request conflicts with how things are. `type()` says which way:
 * `idempotency_key_reused` (the key was used for a different request),
 * `idempotency_in_progress` (the first request with the key has not answered yet) or
 * `mail_not_held` (a mail that is not quarantined cannot be released or discarded).
 */
class RelayConflict extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay refused the request: it conflicts with the current state.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
