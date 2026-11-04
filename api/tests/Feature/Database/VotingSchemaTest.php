<?php

use App\Enums\VoteStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function voteRow(string $userId, string $productId, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(), 'user_id' => $userId, 'product_id' => $productId, 'status' => 'counted',
        'voted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

function sqlState(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

it('has the documented columns on each voting table', function (string $table, array $columns) {
    // Given the migrated schema
    // When the column names are read
    $names = DB::table('information_schema.columns')->where('table_name', $table)->pluck('column_name')->all();

    // Then every documented column exists
    expect($names)->toEqualCanonicalizing($columns);
})->with([
    'votes' => ['votes', ['id', 'user_id', 'product_id', 'status', 'voted_at', 'removed_at', 'toggle_count', 'ip_hash', 'device_hash', 'user_agent', 'voided_at', 'voided_reason', 'created_at', 'updated_at']],
    'product_hourly_stats' => ['product_hourly_stats', ['product_id', 'hour_start', 'votes', 'views']],
    'daily_rankings' => ['daily_rankings', ['launch_date', 'product_id', 'rank', 'votes_count', 'score', 'frozen_at']],
    'rank_snapshots' => ['rank_snapshots', ['product_id', 'captured_at', 'rank', 'score']],
]);

it('creates the indexes and constraints the voting reads rely on', function () {
    // Given the migrated schema
    // When the index and constraint names are read
    $indexes = DB::table('pg_indexes')->whereIn('tablename', ['votes', 'product_hourly_stats', 'daily_rankings', 'rank_snapshots', 'products'])->pluck('indexdef', 'indexname');
    $constraints = DB::table('pg_constraint')->pluck('conname')->all();

    // Then each one exists
    expect($indexes->keys()->all())->toContain(
        'votes_user_id_product_id_unique', 'votes_product_id_voted_at_index', 'votes_ip_hash_index', 'votes_device_hash_index',
        'product_hourly_stats_pkey', 'daily_rankings_pkey', 'daily_rankings_launch_date_rank_unique', 'rank_snapshots_pkey',
        'products_live_votes_launch_date_id_index',
    );
    expect($indexes['products_live_votes_launch_date_id_index'])->toContain('votes_count DESC, launch_date DESC, id')->toContain("'live'::text");
    expect($constraints)->toContain(
        'votes_status_check', 'votes_voided_matches_status', 'product_hourly_stats_hour_start_on_the_hour',
        'daily_rankings_rank_positive', 'rank_snapshots_rank_positive',
    );
});

it('stores a vote with a ULID id and the documented defaults', function () {
    // Given a vote created by the factory
    $vote = Vote::factory()->create();

    // When it is reloaded
    $fresh = Vote::query()->findOrFail($vote->id);

    // Then the id is a ULID, it is counted and active, and the signals are 64 characters
    expect(Str::isUlid($fresh->id))->toBeTrue();
    expect($fresh->status)->toBe(VoteStatus::Counted);
    expect($fresh->removed_at)->toBeNull()->and($fresh->toggle_count)->toBe(0);
    expect($fresh->ip_hash)->toHaveLength(64)->and($fresh->device_hash)->toHaveLength(64);
});

it('refuses an unknown vote status, a voided row without voided_at and a counted row with voided_at', function (array $override, string $constraint) {
    // Given a member and a product
    $user = User::factory()->create();
    $product = Product::factory()->create();

    // When the row is inserted directly
    $insert = fn () => DB::transaction(fn () => DB::table('votes')->insert(voteRow($user->id, $product->id, $override)));

    // Then the CHECK constraint refuses it
    expect($insert)->toThrow(QueryException::class, $constraint);
    expect(sqlState(fn () => DB::table('votes')->insert(voteRow($user->id, $product->id, $override))))->toBe('23514');
})->with([
    'bogus status' => [['status' => 'bogus'], 'votes_status_check'],
    'voided without voided_at' => [['status' => 'voided'], 'votes_voided_matches_status'],
    'counted with voided_at' => [['voided_at' => '2026-10-20 10:00:00'], 'votes_voided_matches_status'],
]);

it('refuses a second vote row for the same member and product', function () {
    // Given a vote
    $vote = Vote::factory()->create();

    // When the same pair is inserted again
    $state = sqlState(fn () => DB::table('votes')->insert(voteRow($vote->user_id, $vote->product_id)));

    // Then the unique index refuses it
    expect($state)->toBe('23505');
});

it('refuses a vote for an unknown product or member', function () {
    // Given a member
    $user = User::factory()->create();

    // When a vote points at an unknown product, and another at an unknown member
    $product = sqlState(fn () => DB::table('votes')->insert(voteRow($user->id, (string) Str::ulid())));
    $member = sqlState(fn () => DB::table('votes')->insert(voteRow((string) Str::ulid(), Product::factory()->create()->id)));

    // Then the foreign keys refuse both
    expect($product)->toBe('23503')->and($member)->toBe('23503');
});

it('refuses a vote hash longer than 64 characters', function () {
    // Given a member and a product
    $user = User::factory()->create();
    $product = Product::factory()->create();

    // When the ip_hash is 65 characters
    $state = sqlState(fn () => DB::table('votes')->insert(voteRow($user->id, $product->id, ['ip_hash' => str_repeat('a', 65)])));

    // Then varchar(64) refuses it (value too long)
    expect($state)->toBe('22001');
});

it('only accepts hourly buckets that start on the hour, one per product and hour', function () {
    // Given a product
    $product = Product::factory()->create();
    $bucket = fn (string $hour) => ['product_id' => $product->id, 'hour_start' => $hour];

    // When an off-the-hour bucket, then a good one and its duplicate are inserted
    $off = sqlState(fn () => DB::table('product_hourly_stats')->insert($bucket('2026-10-20 10:30:00+00')));
    DB::table('product_hourly_stats')->insert($bucket('2026-10-20 10:00:00+00'));
    $dup = sqlState(fn () => DB::table('product_hourly_stats')->insert($bucket('2026-10-20 10:00:00+00')));

    // Then the CHECK and the primary key refuse them, and the defaults are zero
    expect($off)->toBe('23514')->and($dup)->toBe('23505');
    $row = DB::table('product_hourly_stats')->where('product_id', $product->id)->first();
    expect($row->votes)->toBe(0)->and($row->views)->toBe(0);
});

it('refuses a bucket for an unknown product', function () {
    // Given no product with this id
    // When a bucket is inserted
    $state = sqlState(fn () => DB::table('product_hourly_stats')->insert(['product_id' => (string) Str::ulid(), 'hour_start' => '2026-10-20 10:00:00+00']));

    // Then the foreign key refuses it
    expect($state)->toBe('23503');
});

it('guards daily rankings: rank at least 1, unique rank per day, one row per product and day', function () {
    // Given one ranked product on a day
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    $row = fn (Product $p, int $rank, string $day = '2026-10-20') => [
        'launch_date' => $day, 'product_id' => $p->id, 'rank' => $rank, 'votes_count' => 3, 'score' => 1.5, 'frozen_at' => now(),
    ];
    DB::table('daily_rankings')->insert($row($a, 1));

    // When rank 0, a duplicate rank, a duplicate product and the same rank on another day are inserted
    $zero = sqlState(fn () => DB::table('daily_rankings')->insert($row($b, 0)));
    $dupRank = sqlState(fn () => DB::table('daily_rankings')->insert($row($b, 1)));
    $dupProduct = sqlState(fn () => DB::table('daily_rankings')->insert($row($a, 2)));
    $otherDay = sqlState(fn () => DB::table('daily_rankings')->insert($row($b, 1, '2026-10-21')));

    // Then only the other day is accepted
    expect($zero)->toBe('23514')->and($dupRank)->toBe('23505')->and($dupProduct)->toBe('23505')->and($otherDay)->toBeNull();
});

it('refuses a daily ranking for an unknown product', function () {
    // Given no product with this id
    // When a ranking row is inserted
    $state = sqlState(fn () => DB::table('daily_rankings')->insert([
        'launch_date' => '2026-10-20', 'product_id' => (string) Str::ulid(), 'rank' => 1, 'votes_count' => 0, 'score' => 0, 'frozen_at' => now(),
    ]));

    // Then the foreign key refuses it
    expect($state)->toBe('23503');
});

it('guards rank snapshots: rank at least 1, one row per product and capture time', function () {
    // Given a product with a snapshot
    $product = Product::factory()->create();
    $row = fn (int $rank) => ['product_id' => $product->id, 'captured_at' => '2026-10-20 10:10:00+00', 'rank' => $rank, 'score' => 0.5];
    DB::table('rank_snapshots')->insert($row(1));

    // When rank 0 and a duplicate capture are inserted
    $zero = sqlState(fn () => DB::table('rank_snapshots')->insert(['captured_at' => '2026-10-20 10:20:00+00'] + $row(0)));
    $dup = sqlState(fn () => DB::table('rank_snapshots')->insert($row(2)));

    // Then the CHECK and the primary key refuse them
    expect($zero)->toBe('23514')->and($dup)->toBe('23505');
});

it('has no cascading foreign keys on the voting tables', function () {
    // Given the migrated schema
    // When the foreign keys of the voting tables are read (confdeltype 'a' = no action)
    $deleteActions = DB::table('pg_constraint')->where('contype', 'f')
        ->whereIn('conrelid', DB::table('pg_class')->whereIn('relname', ['votes', 'product_hourly_stats', 'daily_rankings', 'rank_snapshots'])->select('oid'))
        ->pluck('confdeltype')->unique()->all();

    // Then none cascades or nulls
    expect($deleteActions)->toBe(['a']);
});

it('selects only active votes with the active scope', function () {
    // Given counted, flagged, removed and voided votes
    $counted = Vote::factory()->create();
    $flagged = Vote::factory()->flagged()->create();
    Vote::factory()->removed()->create();
    Vote::factory()->voided()->create();

    // When the scope is applied
    $ids = Vote::query()->active()->pluck('id')->all();

    // Then removed and voided rows are excluded, flagged is included
    expect($ids)->toEqualCanonicalizing([$counted->id, $flagged->id]);
});

it('builds removed, flagged and voided states that satisfy the CHECKs', function () {
    // Given the factory states
    $removed = Vote::factory()->removed()->create();
    $flagged = Vote::factory()->flagged()->create();
    $voided = Vote::factory()->voided()->create();

    // When reloaded
    // Then each holds the state it names
    expect($removed->fresh()->removed_at)->not->toBeNull()->and($removed->fresh()->status)->toBe(VoteStatus::Counted);
    expect($flagged->fresh()->status)->toBe(VoteStatus::Flagged)->and($flagged->fresh()->removed_at)->toBeNull();
    expect($voided->fresh()->status)->toBe(VoteStatus::Voided)->and($voided->fresh()->voided_at)->not->toBeNull();
});

it('lets a product list its votes', function () {
    // Given a product with a vote
    $vote = Vote::factory()->create();

    // When the relation is read
    $ids = Product::query()->with('votes')->findOrFail($vote->product_id)->votes->pluck('id')->all();

    // Then it holds the vote
    expect($ids)->toBe([$vote->id]);
});

it('judges the on-the-hour rule in UTC whatever the session time zone is', function () {
    // Given a session in a time zone with a half-hour offset, and a product
    DB::statement("SET LOCAL TIME ZONE 'Asia/Kolkata'");
    $product = Product::factory()->create();

    // When a bucket that starts on the UTC hour is inserted, and one on the local hour (10:30 UTC is 16:00 local)
    DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => '2026-10-20 10:00:00+00']);
    $local = sqlState(fn () => DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => '2026-10-20 10:30:00+00']));

    // Then the UTC hour is accepted and the half hour is refused
    expect($local)->toBe('23514');
});

it('serves the all-time top from the leaderboard index', function () {
    // Given some live products and the planner pushed off its other options
    Product::factory()->live()->count(3)->create();
    DB::statement('SET LOCAL enable_seqscan = off');
    DB::statement('SET LOCAL enable_bitmapscan = off');
    DB::statement('SET LOCAL enable_sort = off');

    // When the all-time top query is explained
    $plan = collect(DB::select("EXPLAIN SELECT id FROM products WHERE status = 'live' AND deleted_at IS NULL ORDER BY votes_count DESC, launch_date DESC, id LIMIT 50"))
        ->pluck('QUERY PLAN')->implode("\n");

    // Then it walks the index in order, without a sort
    expect($plan)->toContain('products_live_votes_launch_date_id_index')->not->toContain('Sort');
});
