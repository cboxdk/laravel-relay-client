<?php

declare(strict_types=1);

namespace Cbox\Relay\Client\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One mail this app sent through Relay, by Relay's id (`msg_…`). `status` is the last
 * thing known about it: what Relay answered when it took the mail (`queued`, `blocked`,
 * `quarantined`), then what its events said (`delivered`, `bounced`, `complained`).
 *
 * @property string $id
 * @property string|null $connection
 * @property string|null $project
 * @property string|null $subject
 * @property string|null $mailable
 * @property string $from_address
 * @property int $recipients
 * @property string $status
 * @property int $delivered
 * @property int $bounced
 * @property int $complained
 * @property int $clicked
 * @property CarbonImmutable|null $last_event_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, RelayMessageEvent> $events
 */
class RelayMessage extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'relay_messages';

    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        $connection = config('relay.store.connection');

        return is_string($connection) && $connection !== '' ? $connection : parent::getConnectionName();
    }

    /** @return HasMany<RelayMessageEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(RelayMessageEvent::class, 'message_id')->orderBy('occurred_at')->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recipients' => 'integer',
            'delivered' => 'integer',
            'bounced' => 'integer',
            'complained' => 'integer',
            'clicked' => 'integer',
            'last_event_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
