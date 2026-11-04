<?php

namespace App\Models;

use App\Enums\VoteStatus;
use Database\Factories\VoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property VoteStatus $status
 * @property Carbon $voted_at
 * @property Carbon|null $removed_at
 * @property Carbon|null $voided_at
 * @property int $toggle_count
 */
class Vote extends Model
{
    /** @use HasFactory<VoteFactory> */
    use HasFactory, HasUlids;

    /** Nothing is mass-assigned: the vote actions use forceFill. */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'status' => VoteStatus::class,
            'voted_at' => 'datetime',
            'removed_at' => 'datetime',
            'voided_at' => 'datetime',
            'toggle_count' => 'integer',
        ];
    }

    /**
     * A vote that counts: not taken back and not voided (flagged still counts).
     *
     * @param  Builder<Vote>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('removed_at')
            ->whereIn('status', [VoteStatus::Counted->value, VoteStatus::Flagged->value]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
