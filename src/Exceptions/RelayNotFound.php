<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/** 404: nothing by that id or ref is visible to this key. */
class RelayNotFound extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay found nothing by that reference.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
