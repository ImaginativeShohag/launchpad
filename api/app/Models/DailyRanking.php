<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Read-only after load: no key, so no update, delete, refresh or find. Writes are raw inserts and upserts.
 *
 * One frozen row per product and launch day; insert-only from the app.
 *
 * @property Carbon $launch_date
 * @property string $product_id
 * @property int $rank
 * @property int $votes_count
 * @property float $score
 * @property Carbon $frozen_at
 */
class DailyRanking extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'launch_date' => 'date:Y-m-d',
            'rank' => 'integer',
            'votes_count' => 'integer',
            'score' => 'float',
            'frozen_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
