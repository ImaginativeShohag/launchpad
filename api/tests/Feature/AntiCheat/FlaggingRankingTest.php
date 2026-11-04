<?php

use App\Actions\AntiCheat\FlagVotes;
use App\Actions\Ranking\RecomputeHotScores;
use App\Actions\Ranking\TodayRanking;
use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoidVote;
use App\Actions\Vote\VotedProductIds;
use App\Actions\Vote\VoteSignals;
use App\Actions\Vote\WithdrawVote;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

/**
 * Everything the public and the voters can observe about counts and order, as one comparable value.
 *
 * @param  list<Product>  $products
 * @param  list<User>  $members
 */
function antiCheatFlagObservable(array $products, array $members, User $viewer): array
{
    app(RecomputeHotScores::class)->handle(CarbonImmutable::now('UTC'));
    $now = CarbonImmutable::now('UTC');
    $ids = array_map(fn (Product $p) => $p->id, $products);
    Sanctum::actingAs($viewer);

    return [
        'products' => DB::table('products')->whereIn('id', $ids)->orderBy('id')->get(['id', 'votes_count', 'hot_score'])->all(),
        'buckets' => DB::table('product_hourly_stats')->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('hour_start')->get(['product_id', 'hour_start', 'votes'])->all(),
        'has_voted' => array_map(fn (User $m) => app(VotedProductIds::class)->handle($m, $ids), $members),
        'ranking' => app(TodayRanking::class)->products($now)->map(fn (Product $p) => [$p->id, $p->votes_count])->all(),
        'today' => test()->getJson('/api/v1/today')->getContent(),
        'pages' => array_map(fn (Product $p) => test()->getJson('/api/v1/products/'.$p->slug)->getContent(), $products),
    ];
}

it('moves no count, bucket, order, has_voted or public payload when votes are flagged and unflagged (property-style)', function () {
    // Given three products launching today, eight members, a fixed seed and a clock stepping through the day
    mt_srand(20261022);
    $clock = CarbonImmutable::parse('2026-10-21 01:00:00', 'UTC');
    test()->travelTo($clock);
    $products = array_map(fn () => antiCheatLiveProduct(), range(1, 3));
    $members = User::factory()->count(8)->create()->all();
    $viewer = User::factory()->create();
    $signals = new VoteSignals(null, null, null);
    $events = [];
    Event::listen('*', function (string $name) use (&$events) {
        $events[] = $name;
    });
    $flaggedTotal = 0;
    $unflaggedTotal = 0;
    $flagRuns = 0;

    // When 40 random operations run: cast, withdraw, void, and flagging passes with scores that flip at random
    for ($i = 0; $i < 40; $i++) {
        $clock = $clock->addMinutes(20);
        test()->travelTo($clock);
        $member = $members[mt_rand(0, 7)];
        $product = $products[mt_rand(0, 2)];

        switch (mt_rand(0, 3)) {
            case 0:
                app(CastVote::class)->handle($member, $product->id, $signals);
                break;
            case 1:
                app(WithdrawVote::class)->handle($member, $product->id);
                break;
            case 2:
                $vote = Vote::query()->where('product_id', $product->id)->inRandomOrder()->first();
                if ($vote !== null) {
                    app(VoidVote::class)->handle($vote->id, 'property test');
                }
                break;
            default:
                foreach ($members as $m) {
                    antiCheatSetScore($m, mt_rand(0, 1) === 1 ? 80 : 10);
                }
                $before = antiCheatFlagObservable($products, $members, $viewer);
                $events = [];
                $result = app(FlagVotes::class)->handle(antiCheatNow());
                $seen = array_values(array_filter($events, fn (string $e) => str_starts_with($e, 'App\\')));
                $after = antiCheatFlagObservable($products, $members, $viewer);

                // Then nothing observable moved and no application event fired
                expect($after)->toEqual($before);
                expect($seen)->toBe([]);
                $flaggedTotal += $result->flagged;
                $unflaggedTotal += $result->unflagged;
                $flagRuns++;
        }
    }

    // And the sequence really flagged and unflagged votes, and the stored counters still equal the active votes
    expect($flagRuns)->toBeGreaterThan(3)->and($flaggedTotal)->toBeGreaterThan(0)->and($unflaggedTotal)->toBeGreaterThan(0);
    foreach ($products as $product) {
        expect($product->fresh()->votes_count)->toBe(Vote::query()->where('product_id', $product->id)->active()->count());
        expect((int) DB::table('product_hourly_stats')->where('product_id', $product->id)->sum('votes'))->toBe($product->fresh()->votes_count);
    }
});
