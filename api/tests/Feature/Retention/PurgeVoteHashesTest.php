<?php

use App\Actions\Retention\PurgeVoteHashes;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Enums\VoteStatus;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

// Today is 2026-10-21, so the default 90 day cutoff day is 2026-07-23: that launch day is kept, the day before is purged.
beforeEach(fn () => $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC')));

function retentionHasSignals(Vote $vote): bool
{
    $row = DB::table('votes')->where('id', $vote->id)->first();

    return $row->ip_hash !== null || $row->device_hash !== null || $row->user_agent !== null;
}

it('empties the signals of votes on launches older than the window and keeps the boundary day', function () {
    // Given votes on launches 91, 90 and 89 days back
    $older = retentionVote('2026-07-22');
    $atBoundary = retentionVote('2026-07-23');
    $newer = retentionVote('2026-07-24');

    // When the purge runs
    $purged = app(PurgeVoteHashes::class)->purge(retentionNow());

    // Then only the 91 day old vote lost its signals
    expect($purged)->toBe(1);
    expect(retentionHasSignals($older))->toBeFalse()->and(retentionHasSignals($atBoundary))->toBeTrue()->and(retentionHasSignals($newer))->toBeTrue();
});

it('keeps the vote itself: status, product, user, timestamps, counts and updated_at', function (string $state) {
    // Given an old vote in each state, and the product counters
    $vote = retentionVote('2026-05-01');
    $vote->forceFill(match ($state) {
        'counted' => [],
        'flagged' => ['status' => VoteStatus::Flagged],
        'removed' => ['removed_at' => now()->subDays(100), 'toggle_count' => 3],
        'voided' => ['status' => VoteStatus::Voided, 'voided_at' => now()->subDays(100), 'voided_reason' => 'account_deleted'],
    })->save();
    DB::table('votes')->where('id', $vote->id)->update(['updated_at' => '2026-05-02 10:00:00']);
    DB::table('products')->where('id', $vote->product_id)->update(['votes_count' => 42]);
    $before = (array) DB::table('votes')->where('id', $vote->id)->first();
    $productBefore = (array) DB::table('products')->where('id', $vote->product_id)->first();

    // When the purge runs
    app(PurgeVoteHashes::class)->purge(retentionNow());

    // Then only the three signal columns changed
    $after = (array) DB::table('votes')->where('id', $vote->id)->first();
    $changed = array_keys(array_diff_assoc($before, $after));
    sort($changed);
    expect($changed)->toBe(['device_hash', 'ip_hash', 'user_agent']);
    expect($after['ip_hash'])->toBeNull()->and($after['device_hash'])->toBeNull()->and($after['user_agent'])->toBeNull();
    expect((array) DB::table('products')->where('id', $vote->product_id)->first())->toEqual($productBefore);
})->with(['counted', 'flagged', 'removed', 'voided']);

it('is idempotent: a second run changes nothing', function () {
    // Given one old vote, purged once, and one fresh vote
    $old = retentionVote('2026-05-01');
    $fresh = retentionVote('2026-10-21');
    expect(app(PurgeVoteHashes::class)->purge(retentionNow()))->toBe(1);
    $snapshot = DB::table('votes')->orderBy('id')->get()->all();

    // When it runs again
    $second = app(PurgeVoteHashes::class)->purge(retentionNow());

    // Then nothing was touched
    expect($second)->toBe(0)->and(DB::table('votes')->orderBy('id')->get()->all())->toEqual($snapshot);
    expect(retentionHasSignals($old))->toBeFalse()->and(retentionHasSignals($fresh))->toBeTrue();
});

it('also purges a vote that only has some of the signals', function (array $left) {
    // Given an old vote with just one signal left
    $vote = retentionVote('2026-05-01', ['ip_hash' => null, 'device_hash' => null, 'user_agent' => null, ...$left]);

    // When the purge runs
    $purged = app(PurgeVoteHashes::class)->purge(retentionNow());

    // Then it is emptied and counted once
    expect($purged)->toBe(1)->and(retentionHasSignals($vote))->toBeFalse();
})->with([
    'ip only' => [['ip_hash' => str_repeat('c', 64)]],
    'device only' => [['device_hash' => str_repeat('d', 64)]],
    'agent only' => [['user_agent' => 'Mozilla']],
]);

it('never touches votes on current or future launch days', function () {
    // Given votes on today's and tomorrow's launches
    $today = retentionVote('2026-10-21');
    $tomorrow = retentionVote('2026-10-22');

    // When the purge runs with a window at its minimum
    app(Settings::class)->set(SettingKey::RetentionVoteHashesDays, 7, User::factory()->role(Role::Admin)->create());
    $purged = app(PurgeVoteHashes::class)->purge(retentionNow());

    // Then neither lost its signals
    expect($purged)->toBe(0)->and(retentionHasSignals($today))->toBeTrue()->and(retentionHasSignals($tomorrow))->toBeTrue();
});

it('moves the cutoff when the setting changes', function () {
    // Given a vote on a launch 40 days back and the default window of 90 days
    $vote = retentionVote('2026-09-11');
    expect(app(PurgeVoteHashes::class)->purge(retentionNow()))->toBe(0);

    // When an admin shortens the window to 30 days
    app(Settings::class)->set(SettingKey::RetentionVoteHashesDays, 30, User::factory()->role(Role::Admin)->create());

    // Then that vote is purged
    expect(app(PurgeVoteHashes::class)->purge(retentionNow()))->toBe(1)->and(retentionHasSignals($vote))->toBeFalse();
});

it('works through chunks and honours the per-run cap', function () {
    // Given seven old votes on one product and a fresh one
    $product = Product::factory()->live('2026-05-01')->create();
    foreach (range(1, 7) as $i) {
        Vote::factory()->create(['product_id' => $product->id]);
    }
    $fresh = retentionVote('2026-10-21');
    $purge = retentionPurger(PurgeVoteHashes::class, 2, 2);

    // When it runs twice with a cap of two chunks of two
    $first = $purge->purge(retentionNow());
    $firstCapped = $purge->capped;
    $left = DB::table('votes')->whereNotNull('ip_hash')->where('product_id', $product->id)->count();
    $second = $purge->purge(retentionNow());

    // Then four, then the other three; the fresh vote keeps its signals
    expect($first)->toBe(4)->and($firstCapped)->toBeTrue()->and($left)->toBe(3)->and($second)->toBe(3)->and($purge->capped)->toBeFalse();
    expect(retentionHasSignals($fresh))->toBeTrue()->and(DB::table('votes')->whereNotNull('ip_hash')->count())->toBe(1);
});

it('builds a lock clause naming the vote rows only (a SQL text check, not a concurrency test)', function () {
    // Given an old vote and the SQL the purge really runs (captured)
    retentionVote('2026-05-01');
    $captured = retentionCaptureSql(fn () => app(PurgeVoteHashes::class)->purge(retentionNow()), 'WITH picked');
    $sql = $captured[0] ?? '';

    // When the lock clause is read
    // Then it names the votes alias with the lock the UPDATE itself takes, and no product or user row
    expect($sql)->toContain('AS MATERIALIZED')->toContain('FOR NO KEY UPDATE OF v SKIP LOCKED')->not->toContain('users')->not->toContain('FOR SHARE');
});

it('leaves votes of a product without a launch day untouched', function () {
    // Given a draft and a pending product (launch_date is NULL) that carry votes
    $draft = Product::factory()->create();
    $pending = Product::factory()->pending()->create();
    $votes = [Vote::factory()->create(['product_id' => $draft->id]), Vote::factory()->create(['product_id' => $pending->id])];
    expect($draft->fresh()->launch_date)->toBeNull();

    // When the purge runs
    $purged = app(PurgeVoteHashes::class)->purge(retentionNow());

    // Then nothing is purged (NULL < date is not true)
    expect($purged)->toBe(0)->and(retentionHasSignals($votes[0]))->toBeTrue()->and(retentionHasSignals($votes[1]))->toBeTrue();
});

it('moves the boundary day exactly at UTC midnight, whatever zone the instant carries', function () {
    // Given votes on launch days 2026-07-22 and 2026-07-23
    $a = retentionVote('2026-07-22');
    $b = retentionVote('2026-07-23');

    // When the run happens one second before UTC midnight (given in UTC+6, where it is already the 22nd)
    $before = CarbonImmutable::parse('2026-10-22 05:59:59', 'Asia/Dhaka');
    app(PurgeVoteHashes::class)->purge($before);

    // Then still today = 21 Oct: the 22 Jul vote is purged, the 23 Jul vote (exactly 90 days back) is kept
    expect(retentionHasSignals($a))->toBeFalse()->and(retentionHasSignals($b))->toBeTrue();

    // And one second after UTC midnight the 23 Jul vote is purged too
    app(PurgeVoteHashes::class)->purge(CarbonImmutable::parse('2026-10-22 00:00:01', 'UTC'));
    expect(retentionHasSignals($b))->toBeFalse();
});

it('uses the partial unpurged-signals index to find the rows', function () {
    // Given many old votes of which most are already purged (so the partial index is the small one), analysed
    foreach (range(1, 40) as $i) {
        retentionVote('2026-05-01');
    }
    DB::table('votes')->whereIn('id', DB::table('votes')->orderBy('id')->limit(36)->pluck('id'))->update(['ip_hash' => null, 'device_hash' => null, 'user_agent' => null]);
    DB::statement('ANALYZE votes');
    DB::statement('ANALYZE products');
    $captured = retentionCaptureSql(fn () => retentionPurger(PurgeVoteHashes::class, 5, 1)->purge(retentionNow()), 'WITH picked');
    $sql = $captured[0] ?? '';

    // When its plan is read with scans, sorts and the other join methods disabled (a nested loop into votes remains)
    foreach (['enable_hashjoin', 'enable_mergejoin', 'enable_material'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
    $plan = retentionPlan($sql);

    // Then the votes side is read through votes_unpurged_signals_index
    expect($plan)->toContain('votes_unpurged_signals_index');
});

it('never reaches SQL with a corrupt setting value and falls back to the default window, not zero', function (mixed $stored) {
    // Given a stored value that bypassed validation, a vote 60 days back (inside the default 90) and one 120 days back
    retentionStoreRaw(SettingKey::RetentionVoteHashesDays, $stored);
    $inside = retentionVote('2026-08-22');
    $outside = retentionVote('2026-06-23');
    $bindings = [];
    DB::listen(function ($query) use (&$bindings) {
        array_push($bindings, ...$query->bindings);
    });

    // When the purge runs
    app(PurgeVoteHashes::class)->purge(retentionNow());

    // Then the default applied and nothing but scalars was bound
    expect(retentionHasSignals($inside))->toBeTrue()->and(retentionHasSignals($outside))->toBeFalse();
    foreach ($bindings as $binding) {
        expect($binding)->not->toBeArray()->and($binding)->not->toBeObject()->and((string) $binding)->not->toContain('junk');
    }
})->with([
    'zero' => [0], 'negative' => [-90], 'below the floor' => [1], 'above the ceiling' => [4000], 'float' => [0.5], 'bool' => [true],
    'garbage string' => ['junk'], 'array' => [['junk']], 'object' => [['junk' => 1]], 'null' => [null],
]);

it('leaves a current-day vote castable: the cast writes fresh signals after a purge ran', function () {
    // Given a purge that already ran, and a product launching today
    $old = retentionVote('2026-05-01');
    app(PurgeVoteHashes::class)->purge(retentionNow());
    $member = signedInMember();
    $product = Product::factory()->live('2026-10-21')->create();

    // When the member votes on today's launch
    $this->putJson("/api/v1/products/{$product->id}/vote")->assertOk()->assertExactJson(['data' => ['voted' => true, 'votes_count' => 1]]);

    // Then the new vote carries its signals, and the old one is still purged and counted
    $vote = Vote::query()->where('user_id', $member->id)->sole();
    expect($vote->ip_hash)->toHaveLength(64)->and($vote->device_hash)->toHaveLength(64);
    expect(retentionHasSignals($old))->toBeFalse()->and(DB::table('votes')->where('id', $old->id)->value('status'))->toBe('counted');
});

it('touches at most one chunk of rows whatever plan the planner picks', function (array $off) {
    // Given twelve eligible old votes, a chunk of three, and a planner pushed towards a given join strategy
    $product = Product::factory()->live('2026-05-01')->create();
    foreach (range(1, 12) as $i) {
        Vote::factory()->create(['product_id' => $product->id]);
    }
    DB::statement('ANALYZE votes');
    retentionPlannerOff($off);

    // When one chunk runs
    $purged = retentionPurger(PurgeVoteHashes::class, 3, 1)->purge(retentionNow());

    // Then exactly three votes were emptied and nine still hold their signals
    expect($purged)->toBe(3)->and(DB::table('votes')->whereNull('ip_hash')->count())->toBe(3)->and(DB::table('votes')->whereNotNull('ip_hash')->count())->toBe(9);
})->with(retentionPlannerModes());
