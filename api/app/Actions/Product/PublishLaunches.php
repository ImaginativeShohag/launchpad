<?php

namespace App\Actions\Product;

use App\Enums\ProductStatus;
use App\Events\ProductWentLive;
use App\Models\Product;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishLaunches
{
    public const BATCH = 100;

    /** How many products failed to publish in the last run (they stay scheduled and are retried next minute). */
    public int $failed = 0;

    public function __construct(private readonly int $batch = self::BATCH) {}

    /**
     * Moves every `scheduled` product whose launch day (UTC) has begun to `live`. Idempotent (a published product
     * is no longer scheduled), and a missed run is caught up by the next one because the filter is `<= today`.
     *
     * Rows are taken in id order with SKIP LOCKED: a founder withdrawing or re-dating a product keeps its row for a
     * moment, and the scheduler simply picks it up next minute instead of queueing behind them. Each product is
     * published in its own savepoint, so one that fails (and its failed SQL statement) does not poison the batch (L2).
     *
     * @return int how many products went live in this run
     */
    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('UTC');
        $today = $now->toDateString();
        $published = 0;
        $this->failed = 0;
        $afterId = '';

        do {
            $dueCount = 0;
            $lastId = '';

            try {
                DB::transaction(function () use ($today, $now, $afterId, &$published, &$dueCount, &$lastId) {
                    $due = Product::query()
                        ->where('status', ProductStatus::Scheduled->value)
                        ->where('launch_date', '<=', $today)
                        // A suspended or deleted maker's launch waits (it stays scheduled) until the account is active again.
                        ->whereExists(fn ($maker) => $maker->selectRaw('1')->from('users')->whereColumn('users.id', 'products.maker_id')->whereNull('users.deleted_at')->whereNull('users.suspended_at'))
                        ->where('id', '>', $afterId)
                        ->orderBy('id')
                        ->limit($this->batch)
                        ->lock('for update skip locked')
                        ->get();

                    $dueCount = $due->count();
                    $lastId = (string) $due->last()?->getKey();

                    foreach ($due as $product) {
                        try {
                            DB::transaction(function () use ($product, $now): void {
                                $product->transitionTo(ProductStatus::Live);
                                $product->forceFill(['launched_at' => $now])->save();

                                // The audit row is part of going live: if it cannot be written, the product waits.
                                Audit::record('product.went_live', null, $product, ['launch_date' => $product->launch_date?->toDateString()]);
                                ProductWentLive::dispatch((string) $product->getKey());
                            });

                            $published++;
                        } catch (Throwable $e) {
                            $this->failed++;
                            report($e);
                        }
                    }
                });
            } catch (Throwable $e) {
                // Raised after the batch committed (a throwing event listener): the rows are live, so keep going.
                report($e);
            }

            $afterId = $lastId;
        } while ($dueCount === $this->batch);

        return $published;
    }
}
