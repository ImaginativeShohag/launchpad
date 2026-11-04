<?php

namespace App\Models;

use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use Database\Factories\RiskSignalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One finding of the abuse detection. The weight is frozen at detection; `details` holds ids and counts only.
 * Written by `SignalWriter` (raw insert) and by staff review (`forceFill`); nothing is mass-assigned.
 *
 * @property RiskSignalKind $kind
 * @property string|null $user_id
 * @property string|null $product_id
 * @property int $weight
 * @property array<string, mixed> $details
 * @property string $dedupe_key
 * @property Carbon $detected_at
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property RiskOutcome|null $outcome
 * @property string|null $review_note
 */
class RiskSignal extends Model
{
    /** @use HasFactory<RiskSignalFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'kind' => RiskSignalKind::class,
            'outcome' => RiskOutcome::class,
            'details' => 'array',
            'weight' => 'integer',
            'detected_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Signals nobody has reviewed yet.
     *
     * @param  Builder<RiskSignal>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('reviewed_at');
    }

    /** `open`, `confirmed` or `dismissed`. */
    public function status(): string
    {
        return $this->outcome === null ? 'open' : $this->outcome->value;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
