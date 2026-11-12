<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Cbox\Relay\Client\Data\Problem;
use Throwable;

/**
 * 422: Relay read the request and found it invalid. `$errors` holds the messages per
 * field, as Relay's problem body listed them.
 */
class RelayInvalid extends RelayException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message = 'Cbox Relay found the request invalid.',
        public readonly array $errors = [],
        ?Problem $problem = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $problem, $previous);
    }

    /**
     * The messages for one field; empty when Relay had none for it.
     *
     * @return list<string>
     */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }
}
