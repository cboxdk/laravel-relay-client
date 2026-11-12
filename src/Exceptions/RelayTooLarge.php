<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/** 413: the request body is larger than Relay takes (an event payload may be 256 KB). Sending it again will not help. */
class RelayTooLarge extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay refused the request: it is too large.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
