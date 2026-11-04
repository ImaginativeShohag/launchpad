<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member following a topic. Composite key, so no Eloquent writes: the follow actions use the query builder.
 *
 * @property string $user_id
 * @property string $topic_id
 * @property Carbon $created_at
 */
class TopicFollow extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [];

    public const UPDATED_AT = null;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => MicrosecondTimestamp::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Topic, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
