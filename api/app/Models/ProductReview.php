<?php

namespace App\Models;

use App\Enums\ReviewDecision;
use Database\Factories\ProductReviewFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ReviewDecision $decision
 * @property Carbon $created_at
 */
class ProductReview extends Model
{
    /** @use HasFactory<ProductReviewFactory> */
    use HasFactory, HasUlids;

    /** Append-only: a trigger blocks UPDATE/DELETE/TRUNCATE, so there is no updated_at either. */
    public $timestamps = false;

    protected $fillable = ['reviewer_id', 'decision', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['decision' => ReviewDecision::class, 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
