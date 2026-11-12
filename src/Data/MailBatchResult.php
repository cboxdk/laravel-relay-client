<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Data;

/**
 * What became of one mail of a batch, in the order the mails were given. `$status` is 202
 * with the `$mail` when Relay stored it; otherwise it is the status of the `$problem` a
 * single send would have answered (422 for a mail Relay refuses, 429 when the key's
 * allowance ran out part of the way: send that one again later).
 */
readonly class MailBatchResult
{
    public function __construct(
        public int $status = 202,
        public ?Mail $mail = null,
        public ?Problem $problem = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = Payload::int($data, 'status');
        $mail = Payload::nullableMap($data, 'mail');
        $problem = Payload::nullableMap($data, 'problem');

        return new self(
            status: $status,
            mail: $mail === null ? null : Mail::fromArray($mail),
            problem: $problem === null ? null : Problem::fromArray($problem, $status),
        );
    }

    /** Whether Relay stored this mail. */
    public function stored(): bool
    {
        return $this->mail !== null;
    }
}
