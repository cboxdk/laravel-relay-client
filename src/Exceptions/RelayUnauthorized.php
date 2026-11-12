<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/** 401: the key is missing, wrong or revoked. */
class RelayUnauthorized extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay did not accept the key.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
