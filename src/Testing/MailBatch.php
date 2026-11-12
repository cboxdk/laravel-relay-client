<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Testing;

use Cbox\Relay\Client\Data\MailBatchResult;

/** What a batch answered, kept by the fake so a repeat with the same Idempotency-Key gets it again. */
final readonly class MailBatch
{
    /** @param list<MailBatchResult> $results */
    public function __construct(public array $results = []) {}
}
