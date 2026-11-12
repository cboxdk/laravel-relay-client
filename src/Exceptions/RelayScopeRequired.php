<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/**
 * The call acts for one scope and none was named. The manager throws this before
 * anything is sent; called on the transport directly, it is Relay's 422 with the type
 * `scope_required` (then `$problem` is set). Name the scope with
 * `Relay::forScope($ref)` or `relay.scope`; with a scope key, which is already locked
 * to its scope, set `relay.scope_key` to true instead.
 */
class RelayScopeRequired extends RelayException
{
    public function __construct(
        string $message = 'This Cbox Relay call acts for one scope and none is set: use Relay::forScope($ref), set RELAY_SCOPE, or set RELAY_SCOPE_KEY=true when the key is a scope key.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
