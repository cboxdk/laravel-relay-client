<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `scope.outbound_paused`: Relay paused the scope's outbound mail for too many bounces
 * or complaints. `$sent`, `$bounced` and `$complained` are the counts it judged on.
 * Relay's operator lifts the pause.
 */
readonly class ScopeOutboundPaused implements RelayEvent
{
    public function __construct(
        public string $scopeId,
        public string $reason = '',
        public int $sent = 0,
        public int $bounced = 0,
        public int $complained = 0,
    ) {}

    public static function type(): string
    {
        return 'scope.outbound_paused';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'scope_id'),
            Payload::string($data, 'reason'),
            Payload::int($data, 'sent'),
            Payload::int($data, 'bounced'),
            Payload::int($data, 'complained'),
        );
    }
}
