<?php

use App\Actions\Export\ExportFiles;
use App\Enums\ExportStatus;
use App\Events\ExportReady;
use App\Models\Export;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

// Committed fixtures: the export build opens a REPEATABLE READ, READ ONLY transaction, which needs transaction level 0.
// The test removes exactly what it created, by id.
it('builds an export with a cold database cache store: no cache write happens inside the read-only snapshot', function () {
    $this->travelTo(dashNow());
    Storage::fake('exports');
    Event::forget(ExportReady::class); // no notification rows to clean up
    $usersBefore = DB::table('users')->count();
    DB::commit();
    $product = null;
    $topicIds = [];
    try {
        // Given a launched product, an empty settings cache in the database store, and that store as the default
        $product = dashProduct();
        $topicIds = DB::table('product_topics')->where('product_id', $product->id)->pluck('topic_id')->all();
        $export = exportFor($product, 'queued');
        config(['cache.default' => 'database']);
        DB::table(config('cache.stores.database.table'))->where('key', 'like', '%settings:%')->delete();
        expect(DB::transactionLevel())->toBe(0);

        // When the job builds it (the snapshot read-only transaction really opens at level 0)
        exportGenerate($export);

        // Then it finished, instead of failing with 25006 on a cache write
        expect($export->refresh()->status)->toBe(ExportStatus::Ready);
        Storage::disk('exports')->assertExists(ExportFiles::path($export));
    } finally {
        config(['cache.default' => 'array']);
        DB::table(config('cache.stores.database.table'))->where('key', 'like', '%settings:%')->delete();
        if ($product !== null) {
            DB::table('exports')->where('product_id', $product->id)->delete();
            DB::table('product_topics')->where('product_id', $product->id)->delete();
            DB::table('product_screenshots')->where('product_id', $product->id)->delete();
            DB::table('products')->where('id', $product->id)->delete();
            DB::table('users')->where('id', $product->maker_id)->delete();
            if ($topicIds !== []) {
                DB::table('topics')->whereIn('id', $topicIds)->delete();
            }
        }
        Cache::forgetDriver('database');
    }

    expect(DB::table('users')->count())->toBe($usersBefore);
});
