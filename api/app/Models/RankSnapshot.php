<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Read-only after load: no key, so no update, delete, refresh or find. Writes are raw inserts and upserts.
 *
 * Where a product stood at a ten-minute mark of its launch day.
 *
 * @property string $product_id
 * @property Carbon $captured_at
 * @property int $rank
 * @property float $score
 */
class RankSnapshot extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['captured_at' => 'datetime', 'rank' => 'integer', 'score' => 'float'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
