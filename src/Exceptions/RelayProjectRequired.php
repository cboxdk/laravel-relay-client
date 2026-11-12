<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/**
 * The call acts for one project and none was named. The manager throws this before
 * anything is sent; called on the transport directly, it is Relay's 422 with the type
 * `project_required` (then `$problem` is set). Name the project with
 * `Relay::forProject($ref)` or `relay.project`; with a project key, which is already locked
 * to its project, set `relay.project_key` to true instead.
 */
class RelayProjectRequired extends RelayException
{
    public function __construct(
        string $message = 'This Cbox Relay call acts for one project and none is set: use Relay::forProject($ref), set RELAY_PROJECT, or set RELAY_PROJECT_KEY=true when the key is a project key.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }
}
