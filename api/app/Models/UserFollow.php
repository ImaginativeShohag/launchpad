<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member following a member. Composite key, so no Eloquent writes: the follow actions use the query builder.
 *
 * @property string $follower_id
 * @property string $followed_id
 * @property Carbon $created_at
 * @property User $followed
 * @property User $follower
 */
class UserFollow extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [];

    /** Only created_at exists (set by the column default), and it keeps its microseconds for the keyset cursor. */
    public const UPDATED_AT = null;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => MicrosecondTimestamp::class];
    }

    /** @return BelongsTo<User, $this> */
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    /** @return BelongsTo<User, $this> */
    public function followed(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_id');
    }
}
