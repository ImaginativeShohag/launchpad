<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Read-only after load: no key, so no update, delete, refresh or find. Writes are raw inserts and upserts.
 *
 * Composite key (product_id, hour_start): reads use where clauses. The actions write with raw upserts.
 *
 * @property string $product_id
 * @property Carbon $hour_start
 * @property int $votes
 * @property int $views
 */
class ProductHourlyStat extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['hour_start' => 'datetime', 'votes' => 'integer', 'views' => 'integer'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
