<?php

use App\Actions\Export\SweepExports;
use App\Actions\Retention\PurgeExports;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

// No row is created before the commit below: the test commits its own fixtures and removes exactly those, by id.
beforeEach(function () {
    $this->travelTo(dashNow());
    Storage::fake('exports');
});

/**
 * Commits a product and three exports in $status (all 30 minutes old and past expiry), holds the first one locked in a
 * second session while $run executes, and cleans up by id.
 */
function exportWithHeldRow(string $status, Closure $run): void
{
    $usersBefore = DB::table('users')->count();
    $productsBefore = DB::table('products')->count();
    DB::commit();
    $products = [];
    $ids = [];
    try {
        $old = CarbonImmutable::now('UTC')->subMinutes(30);
        foreach (range(1, 3) as $_) {
            $product = Product::factory()->create();
            $products[] = $product;
            $ids[] = exportFor($product, $status, ['created_at' => $old, 'updated_at' => $old, ...($status === 'ready' ? ['expires_at' => $old] : [])])->id;
        }
        sort($ids);
        config(['database.connections.pgsql_other' => config('database.connections.'.config('database.default'))]);
        $other = DB::connection('pgsql_other');
        $other->beginTransaction();
        $other->select('SELECT id FROM exports WHERE id = ? FOR UPDATE', [$ids[0]]);
        DB::statement("SET lock_timeout = '2s'");

        try {
            $run($ids);
        } finally {
            $other->rollBack();
        }
    } finally {
        DB::statement('RESET lock_timeout');
        DB::purge('pgsql_other');
        DB::table('exports')->whereIn('product_id', array_map(fn ($p) => $p->id, $products))->delete();
        DB::table('products')->whereIn('id', array_map(fn ($p) => $p->id, $products))->delete();
        DB::table('users')->whereIn('id', array_map(fn ($p) => $p->maker_id, $products))->delete();
    }

    expect(DB::table('users')->count())->toBe($usersBefore)->and(DB::table('products')->count())->toBe($productsBefore)->and(DB::table('exports')->count())->toBe(0);
}

it('skips a stuck processing export another session holds locked and catches it next run', function () {
    exportWithHeldRow('processing', function (array $ids) {
        // Given three stuck processing exports, the first held by a second session
        // When the sweeper runs
        $first = app(SweepExports::class)->handle();

        // Then it failed the two free rows without waiting and left the held one
        expect($first['failed_stalled'])->toBe(2)->and($first['failed'])->toBe(0);
        expect(DB::table('exports')->where('status', 'processing')->pluck('id')->all())->toBe([$ids[0]]);
    });
});

it('skips a ready export another session holds locked when purging and catches it next run', function () {
    exportWithHeldRow('ready', function (array $ids) {
        // Given three expired ready exports, the first held by a second session
        // When the purge runs
        $first = retentionPurger(PurgeExports::class, 10, 1)->purge(CarbonImmutable::now('UTC'));

        // Then it expired the two free rows without waiting and left the held one ready
        expect($first)->toBe(2);
        expect(DB::table('exports')->where('status', 'ready')->pluck('id')->all())->toBe([$ids[0]]);
    });
});
