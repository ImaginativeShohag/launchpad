<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Progress of one digest (daily top five or weekly best) for one period: the product ids chosen once for everyone and a
 * user-id cursor, so a crashed run resumes where it stopped and a finished one is a cheap lookup. Composite key, so the
 * model only reads; every write goes through the query builder in `QueueDigest`.
 *
 * @property string $kind
 * @property Carbon $period_date
 * @property list<string> $product_ids
 * @property string|null $last_user_id
 * @property int $enqueued
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 */
class DigestRun extends Model
{
    protected $fillable = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected function casts(): array
    {
        return [
            'period_date' => 'date:Y-m-d',
            'product_ids' => 'array',
            'enqueued' => 'integer',
            'started_at' => MicrosecondTimestamp::class,
            'completed_at' => MicrosecondTimestamp::class,
        ];
    }
}
