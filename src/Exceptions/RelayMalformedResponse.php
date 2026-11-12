<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/** Relay answered with a success status but a body this client cannot read. */
class RelayMalformedResponse extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay answered with a body this client cannot read.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
