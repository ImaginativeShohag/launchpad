<?php

use App\Actions\Vote\HourlyBuckets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    viewRedisReset();
    $this->travelTo(new DateTimeImmutable('2026-10-21 10:30:00 UTC'));
});

function viewUpsertRow(string $productId): array
{
    $row = DB::table('product_hourly_stats')->where('product_id', $productId)->sole();

    return [$row->votes, $row->views];
}

it('increments a bucket created by the flush (votes 0, views 3) when a vote lands in the same hour', function () {
    // Given a bucket made by the flush
    $product = viewVisibleProduct();
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|direct' => 3]);
    flushRun();
    expect(viewUpsertRow($product->id))->toBe([0, 3]);

    // When a vote adds to that hour
    HourlyBuckets::add($product->id, CarbonImmutable::now('UTC'), 1);

    // Then the bucket holds the vote and keeps its views
    expect(viewUpsertRow($product->id))->toBe([1, 3]);
});

it('keeps both counts when a vote is followed by a flush', function () {
    // Given a bucket created by a vote
    $product = viewVisibleProduct();
    HourlyBuckets::add($product->id, CarbonImmutable::now('UTC'), 1);
    expect(viewUpsertRow($product->id))->toBe([1, 0]);

    // When the flush adds three views to the same hour
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|direct' => 3]);
    flushRun();

    // Then votes and views are both there
    expect(viewUpsertRow($product->id))->toBe([1, 3]);
});

it('leaves views untouched when a vote is taken back', function () {
    // Given a bucket with a vote and views
    $product = viewVisibleProduct();
    HourlyBuckets::add($product->id, CarbonImmutable::now('UTC'), 2);
    flushPending(["p|{$product->id}|".flushHour(10) => 3, "r|{$product->id}|".FLUSH_DAY.'|direct' => 3]);
    flushRun();

    // When one vote is withdrawn
    HourlyBuckets::add($product->id, CarbonImmutable::now('UTC'), -1);

    // Then votes drop by one and views stay
    expect(viewUpsertRow($product->id))->toBe([1, 3]);
});

it('never changes the votes of an existing bucket in the flush', function () {
    // Given a bucket with 5 votes
    $product = viewVisibleProduct();
    HourlyBuckets::add($product->id, CarbonImmutable::now('UTC'), 5);

    // When two flushes add views
    foreach ([2, 4] as $views) {
        flushPending(["p|{$product->id}|".flushHour(10) => $views, "r|{$product->id}|".FLUSH_DAY.'|direct' => $views]);
        flushRun();
    }

    // Then the votes are still 5
    expect(viewUpsertRow($product->id))->toBe([5, 6]);
});
