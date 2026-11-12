<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/**
 * 429: too many requests, or a sending limit. `$retryAfter` is the number of seconds
 * Relay asked the caller to wait (its `Retry-After` header), null when it did not say.
 *
 * A sending limit (`type()` is `sending_limited`) is not about how often you call: the
 * project, or all of its organization's projects together, has sent to as many recipients
 * as it may this hour or day. `scope()` says which. Ask for more with
 * `Relay::sendingLimits()->request()`.
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

    /** Whether Relay refused the mail for a sending limit rather than for calling too often. */
    public function sendingLimited(): bool
    {
        return $this->type() === 'sending_limited';
    }

    /** Whose sending limit it was: `project` or `organization`; null for an ordinary rate limit. */
    public function scope(): ?string
    {
        return $this->problem?->extension('scope');
    }
}
