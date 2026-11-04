<?php

namespace App\Actions\Comment\Concerns;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The two pieces the vote and the comment actions share, kept apart because their callers interleave other checks
 * between them: the vote action judges the launch day and the own product after the product lock and before the
 * voter re-read (error precedence is pinned), the comment actions lock comment rows between the two.
 */
trait LocksCommentableProduct
{
    /**
     * Locks the product row (first step of the global lock order) and checks on the locked row that it is public.
     *
     * @param-out string $today
     *
     * @param  string|null  $today  receives the UTC day the visibility check used (read after the lock wait), so a caller can judge the launch day on the same instant
     *
     * @throws ModelNotFoundException the uniform 404 for a non-ULID, unknown, hidden, soft-deleted or deleted-maker product
     */
    private function lockPublicProduct(string $productId, ?string &$today = null): Product
    {
        // An id that cannot be a ULID is "no such product" and never reaches the database (a NUL byte is a Postgres error).
        if (! Str::isUlid($productId)) {
            throw (new ModelNotFoundException)->setModel(Product::class);
        }

        $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
        // The day is judged after the lock wait: a request that queued across 00:00 UTC must see the new day.
        $today = Carbon::now('UTC')->toDateString();

        if ($product === null
            || $product->status !== ProductStatus::Live
            || $product->launch_date === null
            || $product->launch_date->toDateString() > $today
            || DB::table('users')->where('id', $product->maker_id)->whereNotNull('deleted_at')->exists()) {
            throw (new ModelNotFoundException)->setModel(Product::class);
        }

        return $product;
    }

    /**
     * Re-reads the acting account under FOR SHARE (after the product row and any comment rows, never before): waits
     * for an account deletion that holds the row and then sees it, so nothing is written for an account that is
     * being deleted or was suspended since the request was authorised.
     *
     * @throws AuthorizationException when the actor is suspended or deleted
     */
    private function assertActorAvailable(User $actor): void
    {
        $state = DB::table('users')->where('id', $actor->getKey())->sharedLock()->first(['deleted_at', 'suspended_at']);

        if ($state === null || $state->deleted_at !== null || $state->suspended_at !== null) {
            throw new AuthorizationException;
        }
    }

    /**
     * Like assertActorAvailable, but also takes the other accounts a write is about to reference by foreign key (the
     * insert would otherwise take an implicit FOR KEY SHARE on them one by one), all FOR SHARE in one statement in id
     * order. Only the actor must be available.
     *
     * @param  list<string>  $otherIds
     * @return array<string, \stdClass> the locked states by id (an id that no longer exists is absent)
     *
     * @throws AuthorizationException when the actor is suspended or deleted
     */
    private function lockUsersShared(User $actor, array $otherIds = []): array
    {
        $ids = array_values(array_unique([(string) $actor->getKey(), ...$otherIds]));
        sort($ids, SORT_STRING);

        $states = DB::table('users')->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id', 'deleted_at', 'suspended_at'])->keyBy('id')->all();
        $own = $states[(string) $actor->getKey()] ?? null;

        if ($own === null || $own->deleted_at !== null || $own->suspended_at !== null) {
            throw new AuthorizationException;
        }

        return $states;
    }
}
