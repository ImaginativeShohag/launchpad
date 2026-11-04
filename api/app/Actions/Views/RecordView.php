<?php

namespace App\Actions\Views;

use App\Models\Product;
use App\Models\User;
use App\Support\BotAgents;
use App\Support\ViewCounters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Counts one page view of a publicly visible product, or quietly does not. The only refusal is the uniform 404 for a
 * product the public cannot see; every other outcome (maker, staff, bot, deduped, capped, Redis down) returns normally so
 * the caller (and an attacker) learns nothing about what happened.
 */
final class RecordView
{
    public function __construct(private readonly ViewCounters $counters) {}

    /** @throws ModelNotFoundException */
    public function handle(string $productId, ?User $viewer, ViewSignals $signals): void
    {
        // One primary-key read. Hidden, draft, scheduled for later, taken down, deleted, deleted maker, unknown: all 404.
        $product = Str::isUlid($productId)
            ? Product::query()->visible()->whereMakerAvailable()->whereKey($productId)->first()
            : null;
        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        // The maker refreshing their own page, or a reviewer previewing it, must not inflate the launch numbers.
        if ($viewer !== null && ($viewer->getKey() === $product->maker_id || $viewer->role->isStaff())) {
            return;
        }

        if (BotAgents::isBot($signals->userAgent)) {
            return;
        }

        $source = ReferrerSource::from($signals->referrer);

        try {
            $this->counters->record($product->id, $signals->deviceHash, $signals->ipHash, CarbonImmutable::now('UTC'), $source);
        } catch (Throwable $e) {
            // Redis down: the view is dropped, the request still answers 204. A dashboard counter is never worth a failed page.
            // Reported once a minute at most, or an outage would write one log entry per beacon.
            $this->reportOutage($e);
        }
    }

    private function reportOutage(Throwable $e): void
    {
        try {
            $first = Cache::add('views:outage-reported', 1, 60);
        } catch (Throwable) {
            $first = true; // the cache is down too: report rather than stay silent
        }

        if ($first) {
            report($e);
        }
    }
}
