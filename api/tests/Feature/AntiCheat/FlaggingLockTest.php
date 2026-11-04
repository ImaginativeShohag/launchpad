<?php

use App\Actions\AntiCheat\FlagVotes;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// No row is created before the commit below: the test commits its own fixtures and removes exactly those, by id.

it('skips a vote another session holds locked, catches it next run', function () {
    // Given two committed eligible votes (one of them withdrawn later), and the first vote locked by a second session
    $usersBefore = DB::table('users')->count();
    $productsBefore = DB::table('products')->count();
    DB::commit();
    $users = [];
    $products = [];
    $votes = [];
    try {
        foreach ([1, 2] as $_) {
            $product = Product::factory()->live(antiCheatNow()->toDateString())->create();
            $user = antiCheatSetScore(User::factory()->create(), 90);
            $products[] = $product;
            $users[] = $user;
            $votes[] = antiCheatVote($user, $product);
        }
        config(['database.connections.pgsql_other' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM votes WHERE id = ? FOR UPDATE', [$votes[0]->id]);
        DB::statement("SET lock_timeout = '2s'");

        // When the flagging pass runs while the lock is held
        try {
            $started = microtime(true);
            $result = app(FlagVotes::class)->handle(antiCheatNow());
            $waited = microtime(true) - $started;
        } finally {
            $other->rollBack();
        }

        // Then it did not wait, flagged the free vote only, and the next run flags the other
        expect($waited)->toBeLessThan(1.5)->and($result->flagged)->toBe(1)->and($result->failedChunks)->toBe(0);
        expect(antiCheatVoteStatus($votes[0]))->toBe('counted')->and(antiCheatVoteStatus($votes[1]))->toBe('flagged');
        expect(app(FlagVotes::class)->handle(antiCheatNow())->flagged)->toBe(1)->and(antiCheatVoteStatus($votes[0]))->toBe('flagged');
    } finally {
        DB::statement('RESET lock_timeout');
        DB::purge('pgsql_other');
        DB::table('product_hourly_stats')->whereIn('product_id', array_map(fn ($p) => $p->id, $products))->delete();
        DB::table('votes')->whereIn('id', array_map(fn ($v) => $v->id, $votes))->delete();
        DB::table('products')->whereIn('id', array_map(fn ($p) => $p->id, $products))->delete();
        DB::table('users')->whereIn('id', [...array_map(fn ($u) => $u->id, $users), ...array_map(fn ($p) => $p->maker_id, $products)])->delete();
    }

    expect(DB::table('users')->count())->toBe($usersBefore)->and(DB::table('products')->count())->toBe($productsBefore);
});
