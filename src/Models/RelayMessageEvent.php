<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing Relay said about a mail (`mail.delivered`, `mail.bounced`, …), once per
 * delivery of the webhook. `message_id` is Relay's mail id whether or not this app kept
 * the mail itself.
 *
 * @property int $id
 * @property string $delivery_id
 * @property string $event_id
 * @property string $message_id
 * @property string $type
 * @property string|null $recipient
 * @property array<string, mixed> $data
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $created_at
 */
class RelayMessageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'relay_message_events';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        $connection = config('relay.store.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    /** @return BelongsTo<RelayMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(RelayMessage::class, 'message_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
