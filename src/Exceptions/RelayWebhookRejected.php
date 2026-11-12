<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Exceptions;

use Throwable;

/**
 * A webhook that claims to come from Relay did not prove it, or its body is not one
 * Relay sends. `$reason` is one of the constants; `REASON_NO_SECRET` is this app's own
 * misconfiguration, every other reason is the sender's.
 */
class RelayWebhookRejected extends RelayException
{
    public const string REASON_NO_SECRET = 'no_secret_configured';

    public const string REASON_MISSING_SIGNATURE = 'missing_signature';

    public const string REASON_MALFORMED_SIGNATURE = 'malformed_signature';

    public const string REASON_MISSING_TIMESTAMP = 'missing_timestamp';

    public const string REASON_MALFORMED_TIMESTAMP = 'malformed_timestamp';

    public const string REASON_STALE_TIMESTAMP = 'stale_timestamp';

    public const string REASON_FUTURE_TIMESTAMP = 'future_timestamp';

    public const string REASON_SIGNATURE_MISMATCH = 'signature_mismatch';

    public const string REASON_MALFORMED_BODY = 'malformed_body';

    public function __construct(
        public readonly string $reason = self::REASON_SIGNATURE_MISMATCH,
        ?string $message = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? 'A webhook was refused: '.str_replace('_', ' ', $reason).'.', null, $previous);
    }

    /** Whether the refusal is this app's fault (no secret set) rather than the sender's. */
    public function misconfigured(): bool
    {
        return $this->reason === self::REASON_NO_SECRET;
    }
}
