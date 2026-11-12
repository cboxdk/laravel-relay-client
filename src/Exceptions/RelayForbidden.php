<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/** 403: the key lacks the ability this call needs, or may not act for that project. */
class RelayForbidden extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay refused the request: the key may not do this.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
