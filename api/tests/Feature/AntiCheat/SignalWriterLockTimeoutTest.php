<?php

use App\Actions\AntiCheat\SignalWriter;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// No row is created before the commit below: the test commits its own fixtures and removes exactly those, by id.

it('fails the chunk instead of waiting for ever behind a lock another session holds', function () {
    // Given a committed product and member, with the product row locked FOR UPDATE by a second session (a FreezeDay or an admin action)
    $usersBefore = DB::table('users')->count();
    $productsBefore = DB::table('products')->count();
    DB::commit();
    $product = null;
    $user = null;
    try {
        $product = Product::factory()->live(antiCheatNow()->toDateString())->create();
        $user = User::factory()->create();
        config(['database.connections.pgsql_other' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM products WHERE id = ? FOR UPDATE', [$product->id]);

        try {
            // When the writer needs a key-share lock on that product
            $started = microtime(true);
            $error = null;
            try {
                app(SignalWriter::class)->write([antiCheatFinding('k:timeout', $user->id, $product->id)], antiCheatNow());
            } catch (QueryException $e) {
                $error = $e;
            }
            $waited = microtime(true) - $started;
        } finally {
            $other->rollBack();
        }

        // Then it gave up with a lock timeout (55P03) after about the configured wait, wrote nothing, and the connection works
        expect($error)->toBeInstanceOf(QueryException::class)->and($error->errorInfo[0])->toBe('55P03');
        expect($waited)->toBeGreaterThan(4.0)->toBeLessThan(8.0);
        expect(DB::table('risk_signals')->where('dedupe_key', 'k:timeout')->count())->toBe(0)->and(DB::selectOne('select 1 as one')->one)->toBe(1);
    } finally {
        DB::purge('pgsql_other');
        DB::table('risk_signals')->where('dedupe_key', 'k:timeout')->delete();
        if ($product !== null) {
            DB::table('products')->where('id', $product->id)->delete();
            DB::table('users')->where('id', $product->maker_id)->delete();
        }
        if ($user !== null) {
            DB::table('users')->where('id', $user->id)->delete();
        }
    }

    expect(DB::table('users')->count())->toBe($usersBefore)->and(DB::table('products')->count())->toBe($productsBefore);
});
