<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use App\Enums\ProductStatus;
use App\Exceptions\ProductRuleViolation;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property ProductStatus $status
 * @property Carbon|null $launch_date
 * @property Carbon|null $submitted_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $launched_at
 * @property Carbon|null $taken_down_at
 * @property User $maker
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** Status, owner, dates and counters are never mass-assigned: actions use forceFill. */
    protected $fillable = ['name', 'slug', 'tagline', 'description', 'website_url'];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'launch_date' => 'date:Y-m-d',
            // Keeps microseconds: Eloquent's default would truncate to whole seconds, and submitted_at is the token a
            // review decision must echo back (Task 7, stale_submission).
            'submitted_at' => MicrosecondTimestamp::class,
            'approved_at' => 'datetime',
            'launched_at' => 'datetime',
            'taken_down_at' => 'datetime',
            'votes_count' => 'integer',
            'comments_count' => 'integer',
            'hot_score' => 'float',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function maker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'maker_id');
    }

    /** @return BelongsToMany<Topic, $this> */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'product_topics')->orderBy('topics.position')->orderBy('topics.name');
    }

    /** @return HasMany<ProductLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(ProductLink::class)->orderBy('position');
    }

    /** @return HasMany<ProductScreenshot, $this> */
    public function screenshots(): HasMany
    {
        return $this->hasMany(ProductScreenshot::class)->orderBy('position');
    }

    /** @return HasMany<Vote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    /** @return HasMany<Export, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<ProductReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * The only scope public queries use: live, and the launch day (UTC) has begun.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('status', ProductStatus::Live->value)
            ->where('launch_date', '<=', Carbon::now('UTC')->toDateString());
    }

    /**
     * The one public-listing rule beyond visible(): a deleted maker's card cannot be rendered, so their products are
     * missing from public reads too (a suspended maker's products stay). GET /products/{slug} and the ranked feeds
     * (GET /today, TodayRanking) add it next to visible(). GET /users/{user}/products does not need it: the {user}
     * binding already answers 404 for a deleted user.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeWhereMakerAvailable(Builder $query): void
    {
        $query->whereHas('maker', fn ($maker) => $maker->whereNull('deleted_at'));
    }

    /**
     * The shared public-list topic rule: products linked to the topic with this slug, as a `products.id IN (sub-select)`
     * semi-join. A join would multiply a product that sits in several topics, and cursor lists cannot join (keyset
     * columns must be the products' own). Used by the leaderboards and by search.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeHasTopicSlug(Builder $query, string $slug): void
    {
        $query->whereIn('products.id', fn (QueryBuilder $sub) => $sub
            ->select('product_topics.product_id')
            ->from('product_topics')
            ->join('topics', 'topics.id', '=', 'product_topics.topic_id')
            ->where('topics.slug', $slug));
    }

    /**
     * Moves the status along an allowed edge; the caller saves it together with its other changes, inside the
     * transaction. The stored status is read again under a row lock first, so an instance loaded before
     * another request committed cannot overwrite that request's decision.
     *
     * @throws ProductRuleViolation
     */
    public function transitionTo(ProductStatus $to): void
    {
        $stored = static::withTrashed()->whereKey($this->getKey())->lockForUpdate()->value('status');
        $current = $stored instanceof ProductStatus ? $stored : $this->status;

        if (! $current->canTransitionTo($to)) {
            throw ProductRuleViolation::invalidStatus();
        }

        $this->forceFill(['status' => $to]);
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? Storage::disk((string) config('launchpad.product_disk'))->url($this->logo_path)
            : null;
    }
}
