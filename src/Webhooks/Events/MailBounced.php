<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Webhooks\Events;

use Cbox\Relay\Client\Data\Payload;
use Cbox\Relay\Client\Webhooks\RelayEvent;

/**
 * `mail.bounced`: a recipient's server refused a mail. A `$permanent` bounce put the
 * address on the suppression list; a temporary failure that was given up did not.
 * `$status` is the server's enhanced status code (`5.1.1`), `$diagnostic` what it said.
 */
readonly class MailBounced implements RelayEvent
{
    /**
     * @param  list<string>  $tags  the tags the mail was sent with
     * @param  array<string, string>  $metadata  the metadata the mail was sent with
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $recipient,
        public bool $permanent = true,
        public ?string $status = null,
        public ?string $diagnostic = null,
        public array $tags = [],
        public array $metadata = [],
    ) {}

    public static function type(): string
    {
        return 'mail.bounced';
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: Payload::string($data, 'id'),
            projectId: Payload::string($data, 'project_id'),
            recipient: Payload::string($data, 'recipient'),
            permanent: Payload::bool($data, 'permanent'),
            status: Payload::nullableString($data, 'status'),
            diagnostic: Payload::nullableString($data, 'diagnostic'),
            tags: Labels::tags($data),
            metadata: Labels::metadata($data),
        );
    }
}
