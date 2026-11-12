<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/**
 * 409: the request conflicts with how things are. `type()` says which way:
 * `idempotency_key_reused` (the key was used for a different request),
 * `idempotency_in_progress` (the first request with the key has not answered yet) or
 * `mail_not_held` (a mail that is not quarantined cannot be released or discarded),
 * `mail_not_scheduled` or `limit_request_open` (the project already has a request waiting).
 */
class RelayConflict extends RelayException
{
    public function __construct(
        string $message = 'Cbox Relay refused the request: it conflicts with the current state.',
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }

    /**
     * For `idempotency_key_reused`: the mail Relay accepted the first time the key was
     * used, so a retry whose content differs can see that the first one left. Null when
     * the first call was not a mail, or for any other conflict.
     */
    public function mailId(): ?string
    {
        return $this->problem?->extension('mail_id');
    }
}
