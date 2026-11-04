<?php

use App\Enums\SearchSort;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

afterEach(fn () => searchUnshadowProducts());

/** The captured relevance SQL, then the plan of it on the analysed indexed copy (only bitmap scans allowed). */
function searchRelevancePlan(string $q, bool $stripStatus = false): string
{
    searchLive('Launchpad', 'Ship things', 'A description');
    $sql = searchCaptureMainSql(fn () => searchRun($q));
    expect($sql)->not->toBe('');

    if ($stripStatus) {
        $sql = str_replace("\"status\" = 'live' and ", '', $sql);
    }

    searchShadowProducts();

    return searchPlan($sql, [], searchBitmapOnly());
}

it('reaches both GIN indexes under a BitmapOr for a relevance query, and never scans the whole table', function () {
    // Given the real SQL of a relevance search with a typo arm, planned on the analysed indexed copy
    // When its plan is read with only bitmap scans allowed
    $plan = searchRelevancePlan('lanchpad');

    // Then the full-text and trigram indexes are combined, and there is no sequential scan on products
    expect($plan)->toContain('BitmapOr')->toContain('Bitmap Index Scan on zz_vec_gin')->toContain('Bitmap Index Scan on zz_trgm_gin')
        ->not->toContain('Seq Scan on products');
});

it('uses only the full-text index for a two-character query', function () {
    // Given a two-character relevance search
    // When its plan is read
    $plan = searchRelevancePlan('la');

    // Then the trigram arm is absent
    expect($plan)->toContain('Bitmap Index Scan on zz_vec_gin')->not->toContain('zz_trgm_gin');
});

it('carries the predicate of the partial indexes, without which the planner ignores them', function () {
    // Given the real SQL with its live and not-deleted conditions
    searchLive('Launchpad');
    $sql = searchCaptureMainSql(fn () => searchRun('launchpad'));
    searchShadowProducts();

    // When the status condition is kept and when it is removed
    $with = searchPlan($sql, [], searchBitmapOnly());
    $without = searchPlan(str_replace("\"status\" = 'live' and ", '', $sql), [], searchBitmapOnly());

    // Then only the real query reaches the partial index
    expect($sql)->toContain("\"status\" = 'live'")->toContain('"products"."deleted_at" is null');
    expect($with)->toContain('zz_vec_gin')->and($without)->not->toContain('zz_vec_gin');
});

it('serves votes and newest from the ordered btree indexes with no sort, also on a cursor page', function () {
    // Given 60 products (a third in a topic) and an analysed table
    $topic = Topic::factory()->create(['slug' => 'tools']);
    $maker = User::factory()->create();
    foreach (range(1, 60) as $i) {
        $product = searchLive('Product '.$i, null, null, ['maker_id' => $maker->id, 'votes_count' => $i % 11, 'launch_date' => now('UTC')->subDays($i % 5)->toDateString(), 'launched_at' => now()->subHours($i)]);
        if ($i % 3 === 0) {
            searchTopicLink($product, $topic);
        }
    }
    DB::statement('ANALYZE products');
    DB::statement('ANALYZE product_topics');
    $votesCursor = searchRun(null, 'tools', SearchSort::Votes, 5)->nextCursor;
    $newestCursor = searchRun(null, 'tools', SearchSort::Newest, 5)->nextCursor;

    $shapes = [
        'votes' => [searchCaptureMainSql(fn () => searchRun(null, 'tools', SearchSort::Votes)), 'products_live_votes_launch_date_id_index'],
        'votes cursor' => [searchCaptureMainSql(fn () => searchRun(null, 'tools', SearchSort::Votes, 5, $votesCursor)), 'products_live_votes_launch_date_id_index'],
        'newest' => [searchCaptureMainSql(fn () => searchRun(null, 'tools', SearchSort::Newest)), 'products_status_launched_at_id_index'],
        'newest cursor' => [searchCaptureMainSql(fn () => searchRun(null, 'tools', SearchSort::Newest, 5, $newestCursor)), 'products_status_launched_at_id_index'],
    ];

    // When each plan is read with sequential scans, bitmap scans and sorts disabled
    foreach ($shapes as $label => [$sql, $index]) {
        $plan = searchPlan($sql, [], ['seqscan', 'bitmapscan', 'sort']);

        // Then the ordered index is walked with no separate sort, and the topic is probed through product_topics
        expect([$label, str_contains($plan, $index), str_contains($plan, 'Sort Key'), preg_match('/product_topics_(pkey|topic_id_index)/', $plan)])->toBe([$label, true, false, 1]);
    }
});
