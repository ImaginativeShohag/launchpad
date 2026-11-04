<?php

use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoteSignals;
use App\Actions\Vote\WithdrawVote;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-19 00:05:00 UTC')));

it('keeps votes_count and the hourly buckets equal to the active votes over a random sequence', function () {
    // Given four members and two products launching today, and a fixed seed
    mt_srand(20261019);
    $members = User::factory()->count(4)->create();
    $products = Product::factory()->live('2026-10-19')->count(2)->create();
    $signals = new VoteSignals(null, null, null);
    $clock = new DateTimeImmutable('2026-10-19 00:05:00 UTC');

    // When 60 random casts and withdrawals run while time steps across hours
    foreach (range(1, 60) as $_) {
        $clock = $clock->modify('+'.mt_rand(0, 20).' minutes');
        $this->travelTo($clock);
        $member = $members[mt_rand(0, 3)];
        $product = $products[mt_rand(0, 1)];
        mt_rand(0, 1) === 1
            ? app(CastVote::class)->handle($member, $product->id, $signals)
            : app(WithdrawVote::class)->handle($member, $product->id);
    }

    // Then, per product, the counter and the sum of the buckets both equal the number of active votes
    expect(Vote::query()->count())->toBeGreaterThan(0);
    foreach ($products as $product) {
        $active = Vote::query()->where('product_id', $product->id)->active()->count();
        expect($product->fresh()->votes_count)->toBe($active);
        expect((int) DB::table('product_hourly_stats')->where('product_id', $product->id)->sum('votes'))->toBe($active);

        // And hour by hour: a bucket holds the active votes whose (stored) voted_at falls in that hour
        $expected = DB::table('votes')->where('product_id', $product->id)->whereNull('removed_at')->whereIn('status', ['counted', 'flagged'])
            ->selectRaw("date_trunc('hour', voted_at) as hour, count(*) as n")->groupBy('hour')->pluck('n', 'hour')
            ->mapWithKeys(fn ($n, $hour) => [(string) Carbon::parse($hour)->utc()->toDateTimeString() => (int) $n])->sortKeys()->all();
        $actual = DB::table('product_hourly_stats')->where('product_id', $product->id)->where('votes', '!=', 0)->pluck('votes', 'hour_start')
            ->mapWithKeys(fn ($n, $hour) => [(string) Carbon::parse($hour)->utc()->toDateTimeString() => (int) $n])->sortKeys()->all();
        expect($actual)->toBe($expected);
    }
    expect(Vote::query()->sum('toggle_count'))->toBeGreaterThan(0);
    expect(DB::table('product_hourly_stats')->where('votes', '<', 0)->count())->toBe(0);
});

/** @return list<string> the recorded SQL of the callback */
function recordedSql(Closure $run): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $run();
    $sql = array_map(fn (array $q) => strtolower($q['query']), DB::getQueryLog());
    DB::disableQueryLog();

    return $sql;
}

it('takes the product row lock first, before the votes query', function (string $verb) {
    // Given a voter, a product and (for withdraw) an existing vote
    $voter = User::factory()->create();
    $product = Product::factory()->live('2026-10-19')->create();
    app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));

    // When the action runs and its queries are recorded
    $sql = recordedSql(fn () => $verb === 'cast'
        ? app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null))
        : app(WithdrawVote::class)->handle($voter, $product->id));

    // Then the first FOR UPDATE is on products and precedes any votes query
    $firstLock = collect($sql)->search(fn (string $q) => str_contains($q, 'for update'));
    $firstVotes = collect($sql)->search(fn (string $q) => str_contains($q, 'from "votes"'));
    expect($firstLock)->not->toBeFalse()->and($sql[$firstLock])->toContain('from "products"')
        ->and($firstVotes)->not->toBeFalse()->and($firstLock)->toBeLessThan($firstVotes);
})->with(['cast', 'withdraw']);

it('never issues a FOR UPDATE on the users table', function (string $verb) {
    // Given a voter, a product and a vote
    $voter = User::factory()->create();
    $product = Product::factory()->live('2026-10-19')->create();
    app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));

    // When the action runs
    $sql = recordedSql(fn () => $verb === 'withdraw an active vote'
        ? app(WithdrawVote::class)->handle($voter, $product->id)
        : app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null)));

    // Then no users query takes FOR UPDATE, and the voter is read under FOR SHARE after the product lock
    expect(collect($sql)->filter(fn (string $q) => str_contains($q, 'for update') && str_contains($q, '"users"'))->all())->toBe([]);
    $share = collect($sql)->search(fn (string $q) => str_contains($q, 'from "users"') && str_contains($q, 'for share'));
    $productLock = collect($sql)->search(fn (string $q) => str_contains($q, 'from "products"') && str_contains($q, 'for update'));
    expect($share)->not->toBeFalse()->and($productLock)->toBeLessThan($share);
})->with(['withdraw an active vote', 'cast again after a repeat']);

it('buckets a vote cast just before an hour boundary in the hour it is stored in, and withdraws from the same hour', function () {
    // Given a vote cast at 10:59:59.600, which the timestamptz(0) column would round up to 11:00:00
    $this->travelTo(new DateTimeImmutable('2026-10-19 10:59:59.600000 UTC'));
    $voter = User::factory()->create();
    $product = Product::factory()->live('2026-10-19')->create();
    app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));
    $stored = Vote::query()->where('product_id', $product->id)->firstOrFail()->voted_at->utc()->toDateTimeString();

    // When it is withdrawn later in the day
    $this->travelTo(new DateTimeImmutable('2026-10-19 15:00:00 UTC'));
    app(WithdrawVote::class)->handle($voter, $product->id);

    // Then the stored time is the truncated second and every bucket is back to zero (no hour left at -1)
    expect($stored)->toBe('2026-10-19 10:59:59');
    expect(DB::table('product_hourly_stats')->where('product_id', $product->id)->where('votes', '!=', 0)->count())->toBe(0);
    expect($product->fresh()->votes_count)->toBe(0);
});

it('refuses a vote that waited for the product lock across midnight UTC', function () {
    // Given a cast judged just after 00:00 UTC for a product that launched the day before
    $this->travelTo(new DateTimeImmutable('2026-10-20 00:00:00.200000 UTC'));
    $voter = User::factory()->create();
    $product = Product::factory()->live('2026-10-19')->create();

    // When the member casts
    $call = fn () => app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));

    // Then it is refused as voting_closed and nothing is written
    expect($call)->toThrow(ProductRuleViolation::class);
    expect(Vote::query()->count())->toBe(0)->and($product->fresh()->votes_count)->toBe(0);
});
