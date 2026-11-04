<?php

namespace App\Actions\Follow;

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\Cursor as PaginationCursor;
use Illuminate\Pagination\CursorPaginator as PaginatorImplementation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/**
 * The following feed, built at read time (no feed table, nothing cached): launched, visible products whose maker the
 * member follows or that carry a followed topic, never the member's own, newest launch first. The reasons are
 * attached after the page is chosen, in two queries over the page's ids.
 */
class FollowingFeed
{
    private const MAX_TOPIC_REASONS = 3;

    /**
     * One page of the feed. A member who follows nothing gets an empty page without a products query. Otherwise the
     * ids come from `idsQuery()` (two index-friendly branches merged), the page is cut and hydrated, and the framework
     * paginator is built exactly as `cursorPaginate()` would build it, so the cursor shape and the links are the same.
     *
     * @param  bool|null  $hasFollows  the caller's answer to hasFollows(), to save the two lookups when it already has it
     * @return CursorPaginator<int, Product> maker and topics eager-loaded
     */
    public function page(User $me, int $perPage, ?bool $hasFollows = null): CursorPaginator
    {
        $options = ['path' => Paginator::resolveCurrentPath(), 'cursorName' => 'cursor', 'parameters' => ['launched_at', 'id']];
        $cursor = PaginationCursor::fromEncoded(request()->query('cursor'));

        if (! ($hasFollows ?? $this->hasFollows($me))) {
            return (new PaginatorImplementation(new EloquentCollection, $perPage, $cursor, $options))->appends(['per_page' => $perPage]);
        }

        $rows = $this->idsQuery($me, $perPage + 1, $cursor)->get();
        $ids = $rows->pluck('id')->all();

        $products = Product::query()->whereIn('id', $ids)->with(['maker', 'topics'])->get()->keyBy('id');
        // The query order is the page order (reversed for a "previous" cursor, which the paginator turns around again).
        $items = new EloquentCollection(collect($ids)->map(fn (string $id) => $products->get($id))->filter()->values()->all());

        // per_page rides along on links.next / links.prev, or the second page would silently use the default size.
        return (new PaginatorImplementation($items, $perPage, $cursor, $options))->appends(['per_page' => $perPage]);
    }

    /**
     * The ids and launch instants of the feed, `launched_at desc, id desc` (ascending for a "previous" cursor),
     * `$limit` rows, from two branches merged with UNION (a product that matches both appears once), each applying
     * the visibility rule, the own-product exclusion and the cursor predicate itself:
     *
     *  - makers: for every followed maker (LATERAL), the first rows of products_maker_id_launched_at_id_index;
     *  - topics: the products carrying a followed topic, ordered and cut by the planner's best plan (a rare topic is
     *    a small hashed set; a popular one walks products_status_launched_at_id_index).
     *
     * Neither branch can walk the whole launches table for a member who follows a source that rarely launches.
     */
    public function idsQuery(User $me, int $limit, ?PaginationCursor $cursor = null): QueryBuilder
    {
        $meId = (string) $me->getKey();
        $forward = $cursor === null || $cursor->pointsToNextItems();

        $makers = DB::table('user_follows as f')
            ->where('f.follower_id', $meId)
            ->joinLateral(
                $this->branch($meId, $limit, $cursor, $forward)->whereColumn('products.maker_id', 'f.followed_id'),
                'p',
            )
            ->select('p.id', 'p.launched_at');

        $topics = $this->branch($meId, $limit, $cursor, $forward)
            ->whereIn('products.id', fn ($ids) => $ids->select('pt.product_id')
                ->from('product_topics as pt')
                ->join('topic_follows as tf', 'tf.topic_id', '=', 'pt.topic_id')
                ->where('tf.user_id', $meId));

        return DB::query()->fromSub($makers->union($topics), 'u')
            ->select('u.id', 'u.launched_at')
            ->orderBy('u.launched_at', $forward ? 'desc' : 'asc')
            ->orderBy('u.id', $forward ? 'desc' : 'asc')
            ->limit($limit);
    }

    /**
     * One branch: visible launches of available makers that are not the member's own, after the cursor, in feed
     * order, cut to `$limit`. `visible()` already bounds launch_date to today, so no second upper bound is added.
     */
    private function branch(string $meId, int $limit, ?PaginationCursor $cursor, bool $forward): QueryBuilder
    {
        $query = Product::visible()
            ->whereMakerAvailable()
            ->whereNotNull('launched_at')
            ->where('maker_id', '<>', $meId)
            ->select('products.id', 'products.launched_at');

        if ($cursor !== null) {
            $operator = $forward ? '<' : '>';
            $at = $cursor->parameter('launched_at');
            $id = $cursor->parameter('id');
            $query->where(fn (Builder $q) => $q
                ->where('products.launched_at', $operator, $at)
                ->orWhere(fn (Builder $tie) => $tie->where('products.launched_at', $at)->where('products.id', $operator, $id)));
        }

        return $query
            ->orderBy('products.launched_at', $forward ? 'desc' : 'asc')
            ->orderBy('products.id', $forward ? 'desc' : 'asc')
            ->limit($limit)
            ->toBase();
    }

    /**
     * Why each product of the page is there: at most one member reason first, then at most three topic reasons.
     *
     * @param  Collection<int, Product>  $products  the page, with `maker` loaded
     * @return array<string, list<array<string, mixed>>> product id => reasons
     */
    public function reasons(User $me, Collection $products): array
    {
        $reasons = [];

        if ($products->isEmpty()) {
            return $reasons;
        }

        $meId = (string) $me->getKey();
        $followedMakers = DB::table('user_follows')
            ->where('follower_id', $meId)
            ->whereIn('followed_id', $products->pluck('maker_id')->unique()->values()->all())
            ->pluck('followed_id')
            ->flip();

        foreach ($products as $product) {
            $reasons[$product->getKey()] = [];
            $maker = $product->maker;

            // The maker is loaded and available (the feed query filters on it).
            if ($followedMakers->has($product->maker_id)) {
                $reasons[$product->getKey()][] = [
                    'type' => 'member',
                    'user' => ['id' => $maker->id, 'handle' => $maker->handle, 'name' => $maker->name],
                ];
            }
        }

        $topicRows = DB::table('product_topics as pt')
            ->join('topic_follows as tf', 'tf.topic_id', '=', 'pt.topic_id')
            ->join('topics as t', 't.id', '=', 'pt.topic_id')
            ->where('tf.user_id', $meId)
            ->whereIn('pt.product_id', $products->modelKeys())
            ->orderBy('pt.product_id')->orderBy('t.position')->orderBy('t.name')->orderBy('t.id')
            ->get(['pt.product_id', 't.id', 't.slug', 't.name']);

        $taken = [];
        foreach ($topicRows as $row) {
            $taken[$row->product_id] = ($taken[$row->product_id] ?? 0) + 1;

            if ($taken[$row->product_id] <= self::MAX_TOPIC_REASONS) {
                $reasons[$row->product_id][] = ['type' => 'topic', 'topic' => ['id' => $row->id, 'slug' => $row->slug, 'name' => $row->name]];
            }
        }

        return $reasons;
    }

    /** Whether the member follows anyone or any topic: lets the client show "follow someone" instead of an empty list. */
    public function hasFollows(User $me): bool
    {
        $meId = (string) $me->getKey();

        return DB::table('user_follows')->where('follower_id', $meId)->exists()
            || DB::table('topic_follows')->where('user_id', $meId)->exists();
    }
}
