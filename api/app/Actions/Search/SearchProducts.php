<?php

namespace App\Actions\Search;

use App\Enums\SearchSort;
use App\Exceptions\SearchTimedOut;
use App\Models\Product;
use App\Rules\KeysetCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * GET /search: launched products matching what a visitor typed, in one read-only statement.
 *
 * The statement is built from tokens only (see SearchQuery), every value is a bound parameter, and it runs inside a
 * transaction that exists only to scope the statement cap and the trigram threshold (`set_config(..., true)`). No row is
 * locked and nothing is written: takedown, restore, deletion and edits are already reflected by the partial indexes and
 * the generated columns, so this action keeps no state of its own.
 *
 * Preconditions (the request guarantees them): `$query` is null or non-empty; relevance implies a query; a query or a
 * topic is given; a cursor is shape-valid and bound to this search. A cursor that fails any check here is page one.
 */
class SearchProducts
{
    public function handle(?SearchQuery $query, ?string $topic, SearchSort $sort, int $perPage, ?string $cursor): SearchPage
    {
        $query = $query !== null && ! $query->isEmpty() ? $query : null;
        $after = $this->cursorValues($query, $topic, $sort, $cursor);

        $builder = $this->builder($query, $topic, $sort, $after)->with(['maker.links', 'topics'])->limit($perPage + 1);

        try {
            /** @var Collection<int, Product> $rows */
            $rows = DB::transaction(function () use ($builder, $query): Collection {
                $this->applySettings($query !== null);
                $this->afterSettings();

                return $builder->get();
            });
        } catch (QueryException $e) {
            // Decided on the SQLSTATE only (statement_timeout is 57014); anything else is somebody else's error.
            if (($e->errorInfo[0] ?? null) === '57014') {
                throw SearchTimedOut::make();
            }

            throw $e;
        }

        $page = $rows->take($perPage)->values();
        $last = $page->last();
        $next = $rows->count() > $perPage && $last instanceof Product
            ? SearchCursor::encode($sort, SearchQuery::hashFor($query, $sort->value, $topic), $this->cursorValuesOf($sort, $last))
            : null;

        return new SearchPage($page, $next);
    }

    /** A seam for the timeout test: runs inside the transaction, after the settings are applied. */
    protected function afterSettings(): void {}

    private function applySettings(bool $withQuery): void
    {
        DB::select('select set_config(?, ?, true)', ['statement_timeout', (string) (int) config('launchpad.search_timeout_ms', 2000)]);

        if ($withQuery) {
            DB::select('select set_config(?, ?, true)', ['pg_trgm.word_similarity_threshold', (string) SearchTuning::TRIGRAM_THRESHOLD]);
        }
    }

    /**
     * @param  array<string, string|int>|null  $after  decoded, validated cursor values
     * @return Builder<Product>
     */
    private function builder(?SearchQuery $query, ?string $topic, SearchSort $sort, ?array $after): Builder
    {
        // The same two public scopes as every list (visible() already bounds launch_date to today and the partial indexes'
        // status / deleted_at predicate), plus the shared topic rule.
        $base = Product::visible()->whereMakerAvailable();

        if ($topic !== null) {
            $base->hasTopicSlug($topic);
        }

        $score = null;

        if ($query !== null) {
            [$tsq, $tsqBindings] = $this->tsquery($query);
            $fq = 'launchpad_fold(?)';
            // Typo tolerance is for one word (a misspelt name); with two or more tokens every token must match in the full text.
            $typo = count($query->tokens()) === 1 && $query->allowsTypoMatch() ? " or {$fq} <% products.name_search" : '';

            /** @var literal-string $match built only from literals in this class; every value is a binding */
            $match = "(products.search_vector @@ {$tsq}{$typo})";
            $base->whereRaw($match, [...$tsqBindings, ...($typo === '' ? [] : [$query->phrase()])]);

            if (count($query->tokens()) === 1 && ! $query->allowsTypoMatch()) {
                // A lone one- or two-letter prefix can match thousands of rows, and ts_rank_cd is the costly part: rank by the
                // tier and the exact-name bonus only, so the order falls to votes and id (the same keyset).
                /** @var literal-string $expression */
                $expression = sprintf('round((%d * (products.search_vector @@ %s)::int + (products.name_search = %s)::int)::numeric, %d) as score', SearchTuning::TIER_WEIGHT, $tsq, $fq, SearchTuning::SCORE_DECIMALS);
                $score = [$expression, [...$tsqBindings, $query->phrase()]];
            } else {
                /** @var literal-string $expression */
                $expression = sprintf(
                    'round((%d * (products.search_vector @@ %s)::int + %d * ts_rank_cd(products.search_vector, %s, %d) + word_similarity(%s, products.name_search) + (products.name_search = %s)::int)::numeric, %d) as score',
                    SearchTuning::TIER_WEIGHT, $tsq, SearchTuning::RANK_WEIGHT, $tsq, SearchTuning::TS_RANK_NORMALIZATION, $fq, $fq, SearchTuning::SCORE_DECIMALS,
                );
                $score = [$expression, [...$tsqBindings, ...$tsqBindings, $query->phrase(), $query->phrase()]];
            }
        }

        if ($sort === SearchSort::Relevance && $score !== null) {
            // The score is computed in a derived table so the keyset can compare it; it has no LIMIT, so the planner
            // flattens it and still reaches both GIN indexes (the plan test pins that).
            $builder = Product::query()->fromSub($base->select('products.*')->selectRaw($score[0], $score[1]), 'products')
                ->orderByDesc('score')->orderByDesc('votes_count')->orderBy('id');

            if ($after !== null) {
                $builder->whereRaw(
                    '(products.score < ?::numeric or (products.score = ?::numeric and products.votes_count < ?) or (products.score = ?::numeric and products.votes_count = ? and products.id > ?))',
                    [$after['score'], $after['score'], $after['votes_count'], $after['score'], $after['votes_count'], $after['id']],
                );
            }

            return $builder;
        }

        if ($sort === SearchSort::Newest) {
            // launched_at is nullable; live products always have it, and a NULL must never reach the cursor.
            $base->whereNotNull('products.launched_at')->orderByDesc('products.launched_at')->orderByDesc('products.id');

            if ($after !== null) {
                $base->whereRaw(
                    '(products.launched_at < ?::timestamptz or (products.launched_at = ?::timestamptz and products.id < ?))',
                    [$after['launched_at'], $after['launched_at'], $after['id']],
                );
            }

            return $base;
        }

        // Votes: exactly the Leaderboard order, which products_live_votes_launch_date_id_index serves.
        $base->orderByDesc('products.votes_count')->orderByDesc('products.launch_date')->orderBy('products.id');

        if ($after !== null) {
            $base->whereRaw(
                '(products.votes_count < ? or (products.votes_count = ? and products.launch_date < ?::date) or (products.votes_count = ? and products.launch_date = ?::date and products.id > ?))',
                [$after['votes_count'], $after['votes_count'], $after['launch_date'], $after['votes_count'], $after['launch_date'], $after['id']],
            );
        }

        return $base;
    }

    /**
     * Whole words through plainto_tsquery, the last token as a prefix; folded per token in SQL. The pieces are joined with
     * the tsquery && operator, so no token can ever form a tsquery operator or a syntax error.
     *
     * @return array{0: string, 1: list<string>} the SQL and its bindings
     */
    private function tsquery(SearchQuery $query): array
    {
        $parts = [];
        $bindings = [];

        foreach ($query->wholeTokens() as $token) {
            $parts[] = "plainto_tsquery('simple', launchpad_fold(?))";
            $bindings[] = $token;
        }

        $parts[] = 'launchpad_prefix_tsquery(?)';
        $bindings[] = $query->prefixToken();

        return ['('.implode(' && ', $parts).')', $bindings];
    }

    /**
     * The cursor's values when it is valid for exactly this search and sort, else null (page one).
     *
     * @return array<string, string|int>|null
     */
    private function cursorValues(?SearchQuery $query, ?string $topic, SearchSort $sort, ?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $values = SearchCursor::decode($cursor);
        $shape = SearchCursor::shape($sort);

        if ($values === null || array_keys($values) !== array_keys($shape) || $values['h'] !== SearchQuery::hashFor($query, $sort->value, $topic)) {
            return null;
        }

        return (new KeysetCursor($shape))->passes($cursor) ? $values : null;
    }

    /** @return array<string, string|int> */
    private function cursorValuesOf(SearchSort $sort, Product $last): array
    {
        return match ($sort) {
            // The score is the 4-decimal numeric string the database returned: it round-trips exactly.
            SearchSort::Relevance => ['score' => (string) $last->getAttribute('score'), 'votes_count' => $last->votes_count, 'id' => (string) $last->id],
            SearchSort::Votes => ['votes_count' => $last->votes_count, 'launch_date' => (string) $last->launch_date?->toDateString(), 'id' => (string) $last->id],
            SearchSort::Newest => ['launched_at' => (string) $last->launched_at?->toImmutable()->utc()->format('Y-m-d H:i:sP'), 'id' => (string) $last->id],
        };
    }
}
