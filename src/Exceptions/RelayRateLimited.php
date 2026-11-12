<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/**
 * 429: too many requests. `$retryAfter` is the number of seconds Relay asked the caller
 * to wait (its `Retry-After` header), null when it did not say.
 */
class RelayRateLimited extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay is rate limiting this key.',
        public readonly ?int $retryAfter = null,
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
