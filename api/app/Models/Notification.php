<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use App\Enums\NotificationType;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One in-site (bell) notification. Not Laravel's database-notification layout: `user_id` is a real foreign key and
 * `data` holds ids, a message key and cleaned params (no prose).
 *
 * @property NotificationType $type
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasUuids;

    /** Nothing is mass-assigned: writers use forceFill or the RecordNotification action. */
    protected $fillable = [];

    /**
     * Off on purpose: with timestamps on, Eloquent formats `created_at`/`updated_at` as `Y-m-d H:i:s` before any cast
     * runs and drops the microseconds the keyset cursor and the tie order need. The writers set both columns.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'data' => 'array',
            'read_at' => MicrosecondTimestamp::class,
            'created_at' => MicrosecondTimestamp::class,
            'updated_at' => MicrosecondTimestamp::class,
        ];
    }

    /** @param  Builder<Notification>  $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('notifications.read_at');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
