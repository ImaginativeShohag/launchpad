<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use App\Enums\CommentState;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * No SoftDeletes on purpose: the author's deletion and a staff removal are plain columns, and nothing is hidden by a
 * global scope (the reads decide what is public).
 *
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $removed_at
 * @property Carbon|null $deleted_at
 * @property int $replies_count
 * @property User $author
 * @property Product $product
 * @property User|null $replyTo
 */
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory, HasUlids;

    /** Nothing is mass-assigned: the comment actions use forceFill. */
    protected $fillable = [];

    /**
     * Off on purpose: with timestamps on, Eloquent formats `created_at`/`updated_at` as `Y-m-d H:i:s` before any cast
     * runs and drops the microseconds that the keyset cursors and the tie order need. The actions and the factory set
     * both columns themselves.
     */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            // Both sit in keyset cursors, so they keep their microseconds.
            'created_at' => MicrosecondTimestamp::class,
            'updated_at' => MicrosecondTimestamp::class,
            'edited_at' => MicrosecondTimestamp::class,
            'removed_at' => 'datetime',
            'deleted_at' => 'datetime',
            'replies_count' => 'integer',
        ];
    }

    /**
     * The rows `products.comments_count` counts: neither removed by staff nor deleted by the author.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->whereNull('comments.removed_at')->whereNull('comments.deleted_at');
    }

    /**
     * Comments whose author's account is not deleted (until the clean-up job runs, a deleted account's comments are
     * still stored). An anti-join on the users key, so list queries need no join and keep bare column names.
     *
     * @param  Builder<Comment>  $query
     */
    public function scopeWhereAuthorAlive(Builder $query): void
    {
        $query->whereNotExists(fn ($users) => $users->select(DB::raw(1))->from('users')
            ->whereColumn('users.id', 'comments.user_id')->whereNotNull('users.deleted_at'));
    }

    /** Removed wins over deleted; a row whose (loaded) author deleted their account reads as deleted until the clean-up job runs. */
    public function state(): CommentState
    {
        if ($this->removed_at !== null) {
            return CommentState::Removed;
        }

        if ($this->deleted_at !== null || ($this->relationLoaded('author') && $this->author->isDeleted())) {
            return CommentState::Deleted;
        }

        return CommentState::Visible;
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reply_to_user_id');
    }

    /** @return BelongsTo<Comment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Comment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }
}
