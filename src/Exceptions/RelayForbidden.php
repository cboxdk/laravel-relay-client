<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/**
 * 403: the key lacks the ability this call needs, or may not act for that project. Also
 * `mail_held_by_relay`: the mail is held by Relay's own guard against abuse, which only
 * Relay's operator lets go (see `heldByRelay()`); you may still discard it.
 */
class RelayForbidden extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay refused the request: the key may not do this.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }

    /** Whether a release was refused because Relay's own guard holds the mail. */
    public function heldByRelay(): bool
    {
        return $this->type() === 'mail_held_by_relay';
    }
}
