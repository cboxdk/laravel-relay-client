<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use RuntimeException;
use Throwable;

/**
 * Everything this package throws extends this, so one catch covers Cbox Relay as a
 * whole. Thrown as itself for an error answer none of the subclasses name. `$problem`
 * is the RFC 9457 body Relay sent, when it sent one.
 */
class RelayException extends RuntimeException
{
    public function __construct(
        string $message = 'Cbox Relay refused the request.',
        public readonly ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Relay's own name for the problem, the stable part to branch on: `mail_not_held`,
     * `scope_suspended`, `idempotency_key_reused`. It is the last segment of the problem
     * document's `type`; null when there was no problem document or it named none.
     */
    public function type(): ?string
    {
        $type = $this->problem?->type;

        if ($type === null || $type === 'about:blank') {
            return null;
        }

        $name = substr($type, (int) strrpos('/'.$type, '/'));

        return $name === '' ? null : $name;
    }

    /** The HTTP status Relay answered with; null when there was no answer. */
    public function status(): ?int
    {
        return $this->problem?->status;
    }
}
