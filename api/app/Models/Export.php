<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use App\Enums\ExportStatus;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One export of a product's dashboard (a zip of four CSV files). `file_path` is the key on the private `exports` disk and
 * never leaves the server. Writers use forceFill and set both timestamps themselves (microseconds).
 *
 * @property ExportStatus $status
 * @property string $user_id
 * @property string $product_id
 * @property string|null $file_path
 * @property int|null $file_size
 * @property array<string, int>|null $row_counts
 * @property string|null $error
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [];

    /** Off on purpose: Eloquent would truncate the timestamps to whole seconds. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => ExportStatus::class,
            'row_counts' => 'array',
            'file_size' => 'integer',
            'completed_at' => MicrosecondTimestamp::class,
            'expires_at' => MicrosecondTimestamp::class,
            'created_at' => MicrosecondTimestamp::class,
            'updated_at' => MicrosecondTimestamp::class,
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @param  Builder<Export>  $query
     * @return Builder<Export>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['queued', 'processing', 'ready']);
    }
}
