<?php

use App\Models\Topic;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

/** An analysed products table: 3000 launches over ~50 days by 120 makers, two topics each, plus drafts; returns the signed-in member. */
function feedBulkRows(): User
{
    $me = User::factory()->create();
    $makers = User::factory()->count(120)->create();
    $topics = Topic::factory()->count(20)->create();
    $rows = [];
    $links = [];
    for ($i = 0; $i < 3000; $i++) {
        $at = CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC')->subMinutes($i * 20);
        $live = $i % 10 !== 0;
        $id = (string) Str::ulid();
        $rows[] = [
            'id' => $id, 'maker_id' => $makers[$i % 120]->id, 'slug' => 'bulk-'.$i.'-'.Str::lower(Str::random(4)), 'name' => "Bulk {$i}",
            'status' => $live ? 'live' : 'draft', 'launch_date' => $live ? $at->toDateString() : null, 'launched_at' => $live ? $at : null,
            'votes_count' => 0, 'comments_count' => 0, 'hot_score' => 0, 'created_at' => $at, 'updated_at' => $at,
        ];
        $links[] = ['product_id' => $id, 'topic_id' => $topics[$i % 20]->id];
        $links[] = ['product_id' => $id, 'topic_id' => $topics[($i + 7) % 20]->id];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('products')->insert($chunk);
    }
    foreach (array_chunk($links, 1000) as $chunk) {
        DB::table('product_topics')->insert($chunk);
    }
    foreach ([0, 1, 2, 3] as $k) {
        DB::table('user_follows')->insert(['follower_id' => $me->id, 'followed_id' => $makers[$k]->id]);
    }
    foreach ([0, 1] as $k) {
        DB::table('topic_follows')->insert(['user_id' => $me->id, 'topic_id' => $topics[$k]->id]);
    }
    foreach (['products', 'product_topics', 'topics', 'users', 'user_follows', 'topic_follows'] as $table) {
        DB::statement("ANALYZE {$table}");
    }
    Sanctum::actingAs($me);

    return $me;
}

/** The SQL of the feed's merged id query among the captured statements. */
function feedUnionSql(array $sqls): string
{
    return (string) collect($sqls)->first(fn (string $q) => str_contains($q, ' union '));
}

/** @return array<string, mixed> the plan tree of EXPLAIN (ANALYZE, FORMAT JSON) for $sql */
function feedAnalyzedPlan(string $sql): array
{
    $row = DB::select('EXPLAIN (ANALYZE, FORMAT JSON) '.$sql)[0];

    return json_decode($row->{'QUERY PLAN'}, true)[0]['Plan'];
}

/** Rows read from `products` by every node of the plan: returned plus removed by filters, times loops. */
function feedRowsTouched(array $node, string $relation = 'products'): int
{
    $own = ($node['Relation Name'] ?? null) === $relation
        ? (int) ceil(($node['Actual Rows'] + ($node['Rows Removed by Filter'] ?? 0) + ($node['Rows Removed by Index Recheck'] ?? 0)) * ($node['Actual Loops'] ?? 1))
        : 0;

    return $own + array_sum(array_map(fn (array $child) => feedRowsTouched($child, $relation), $node['Plans'] ?? []));
}

it('serves the real feed query, first page and a real cursor page, from the follow-driven indexes', function () {
    // Given an analysed table with thousands of launches and a member following a few makers and topics
    feedBulkRows();
    $first = $this->getJson('/api/v1/me/feed?per_page=20')->assertOk();
    $cursor = parse_url($first->json('links.next'), PHP_URL_QUERY);

    // When the application's real feed SQL (page one and page two, bindings substituted) is captured
    $one = feedUnionSql(commentCaptureSql(fn () => test()->getJson('/api/v1/me/feed?per_page=20')->assertOk()));
    $two = feedUnionSql(commentCaptureSql(fn () => test()->getJson('/api/v1/me/feed?per_page=20&'.$cursor)->assertOk()));

    // Then the cursor predicate sits in BOTH branches of page two (it is the same id twice), and not on page one
    $afterId = (string) $first->json('data.19.id');
    expect(substr_count($two, $afterId))->toBe(2)->and(substr_count($one, $afterId))->toBe(0);

    // And each plan (sequential scans, bitmap scans and sorts disabled) uses real indexes in every part
    foreach ([$one, $two] as $sql) {
        $plan = commentPlan($sql);
        expect($plan)->toContain('Index Scan Backward using products_maker_id_launched_at_id_index')
            ->toMatch('/Index (Only )?Scan using user_follows_follower_created_index/')
            ->toMatch('/Index (Only )?Scan using product_topics_(pkey|topic_id_index)/')
            ->toMatch('/Index Scan using topic_follows_topic_id_index/')
            ->toContain('products_status_launched_at_id_index')
            ->not->toContain('Seq Scan');
    }
});

it('reads few products for a member who follows one rarely-launching maker and one rare topic among thousands of launches', function () {
    // Given the analysed bulk table and a second member who follows only a maker with 3 launches and a topic with 2 products
    feedBulkRows();
    $sparse = User::factory()->create();
    $rareMaker = User::factory()->create();
    $rareTopic = Topic::factory()->create();
    foreach ([0, 1, 2] as $i) {
        $id = (string) Str::ulid();
        DB::table('products')->insert(['id' => $id, 'maker_id' => $rareMaker->id, 'slug' => 'rare-'.$i, 'name' => "Rare {$i}", 'status' => 'live', 'launch_date' => '2026-09-01',
            'launched_at' => CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC')->addMinutes($i), 'votes_count' => 0, 'comments_count' => 0, 'hot_score' => 0, 'created_at' => now(), 'updated_at' => now()]);
        if ($i < 2) {
            DB::table('product_topics')->insert(['product_id' => $id, 'topic_id' => $rareTopic->id]);
        }
    }
    DB::table('user_follows')->insert(['follower_id' => $sparse->id, 'followed_id' => $rareMaker->id]);
    DB::table('topic_follows')->insert(['user_id' => $sparse->id, 'topic_id' => $rareTopic->id]);
    foreach (['products', 'product_topics', 'topics', 'users', 'user_follows', 'topic_follows'] as $table) {
        DB::statement("ANALYZE {$table}");
    }
    Sanctum::actingAs($sparse);

    // When the real query runs (the planner's own choice, no switches) and is analysed
    $sql = feedUnionSql(commentCaptureSql(fn () => test()->getJson('/api/v1/me/feed?per_page=20')->assertOk()));
    $touched = feedRowsTouched(feedAnalyzedPlan($sql));
    $served = array_column($this->getJson('/api/v1/me/feed?per_page=20')->assertOk()->json('data'), 'slug');

    // Then the three rare products are served and far fewer than the ~2700 launches were read (a walk of the launches index would read them all)
    expect($served)->toHaveCount(3)->and($touched)->toBeLessThan(300);
});

it('reads about one page of products for a prolific followed maker, not all of their launches', function () {
    // Given a member following one maker who launched 1500 products, and nothing else
    $me = User::factory()->create();
    $maker = User::factory()->create();
    $rows = [];
    for ($i = 0; $i < 1500; $i++) {
        $at = CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC')->subMinutes($i * 5);
        $rows[] = ['id' => (string) Str::ulid(), 'maker_id' => $maker->id, 'slug' => 'prolific-'.$i, 'name' => "Prolific {$i}", 'status' => 'live', 'launch_date' => $at->toDateString(),
            'launched_at' => $at, 'votes_count' => 0, 'comments_count' => 0, 'hot_score' => 0, 'created_at' => $at, 'updated_at' => $at];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('products')->insert($chunk);
    }
    DB::table('user_follows')->insert(['follower_id' => $me->id, 'followed_id' => $maker->id]);
    // Every table the feed query touches is analysed, so the planner's own choice does not depend on stats left by other tests.
    foreach (['products', 'product_topics', 'topics', 'users', 'user_follows', 'topic_follows'] as $table) {
        DB::statement("ANALYZE {$table}");
    }
    Sanctum::actingAs($me);

    // When the real query runs with the planner's own choice and is analysed
    $sql = feedUnionSql(commentCaptureSql(fn () => test()->getJson('/api/v1/me/feed?per_page=20')->assertOk()));
    $touched = feedRowsTouched(feedAnalyzedPlan($sql));

    // Then it read a page's worth of rows (the per-maker LIMIT), not the 1500 launches
    expect($touched)->toBeLessThan(150);
});

it('returns the same rows as a plain query over the same data, whichever plan runs', function () {
    // Given the analysed data set and the feed's expected rows computed by independent SQL
    $me = feedBulkRows();
    $expected = DB::select(<<<'SQL'
        SELECT p.id FROM products p
        WHERE p.status = 'live' AND p.launched_at IS NOT NULL AND p.deleted_at IS NULL AND p.maker_id <> ?
          AND (p.maker_id IN (SELECT followed_id FROM user_follows WHERE follower_id = ?)
               OR p.id IN (SELECT pt.product_id FROM product_topics pt JOIN topic_follows tf ON tf.topic_id = pt.topic_id WHERE tf.user_id = ?))
        ORDER BY p.launched_at DESC, p.id DESC LIMIT 25
    SQL, [$me->id, $me->id, $me->id]);

    // When the endpoint is read
    $ids = array_column($this->getJson('/api/v1/me/feed?per_page=25')->assertOk()->json('data'), 'id');

    // Then they agree
    expect($ids)->toBe(array_map(fn ($row) => $row->id, $expected))->and(count($ids))->toBe(25);
});
