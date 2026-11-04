<?php

namespace App\Actions\Product;

use App\Actions\Product\Concerns\LocksOwnedProduct;
use App\Enums\ProductStatus;
use App\Enums\SettingKey;
use App\Events\ProductScheduled;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ScheduleLaunch
{
    use LocksOwnedProduct;

    public function __construct(private readonly Settings $settings) {}

    /**
     * Picks or changes the launch day of an approved or scheduled product.
     *
     * Global lock order (L8), step 1: the maker's whole product set, in id order. Two parallel requests for the same
     * maker therefore queue here and the second one counts the first one's committed row, so the per-day cap cannot
     * be exceeded. The target row is part of that set and is re-read afterwards.
     *
     * @param  string  $date  a Y-m-d string (the request validated its shape)
     *
     * @throws ProductRuleViolation from any other status
     * @throws ValidationException outside the window, or when the cap for that day is reached
     */
    public function handle(User $actor, Product $product, string $date): Product
    {
        DB::transaction(function () use ($actor, $product, $date): void {
            Product::query()->where('maker_id', $actor->getKey())->orderBy('id')->lockForUpdate()->pluck('id');
            $locked = $this->lockOwned($actor, $product);

            if (! in_array($locked->status, [ProductStatus::Approved, ProductStatus::Scheduled], true)) {
                throw ProductRuleViolation::invalidStatus();
            }

            if ($locked->status === ProductStatus::Scheduled && $locked->launch_date?->toDateString() === $date) {
                // already on that day: no event, but the response still shows the locked row
                $product->setRawAttributes($locked->getAttributes(), true);

                return;
            }

            $this->assertInsideWindow($date);
            $this->assertCapNotReached($actor, $locked, $date);

            $from = $locked->status;
            if ($locked->status === ProductStatus::Approved) {
                $locked->transitionTo(ProductStatus::Scheduled);
            }

            $locked->forceFill(['launch_date' => $date])->save();
            Audit::record('product.scheduled', $actor, $locked, ['from_status' => $from->value, 'launch_date' => $date]);
            ProductScheduled::dispatch((string) $locked->getKey(), (string) $actor->getKey());

            $product->setRawAttributes($locked->getAttributes(), true);
        });

        return $product;
    }

    private function assertInsideWindow(string $date): void
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
        } catch (Throwable) {
            $day = null;
        }

        if (! $day instanceof CarbonImmutable || $day->format('Y-m-d') !== $date) {
            throw ProductRuleViolation::launchDate('launch_date_invalid', 'Choose a real date (YYYY-MM-DD).');
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $maxDays = (int) $this->settings->get(SettingKey::LaunchMaxDaysAhead);

        if ($day->eq($today)) {
            throw ProductRuleViolation::launchDate('launch_date_today', "Pick a day from tomorrow to {$maxDays} days ahead (UTC); today is too late.");
        }

        if ($day->lt($today->addDay()) || $day->gt($today->addDays($maxDays))) {
            throw ProductRuleViolation::launchDate('launch_date_out_of_window', "Pick a day from tomorrow to {$maxDays} days ahead (UTC).");
        }
    }

    /** D4: the maker's other scheduled and live products that day. A taken-down product frees its slot. */
    private function assertCapNotReached(User $actor, Product $locked, string $date): void
    {
        $cap = (int) $this->settings->get(SettingKey::LaunchMaxPerMakerPerDay);

        $taken = Product::query()
            ->where('maker_id', $actor->getKey())
            ->whereKeyNot($locked->getKey())
            ->where('launch_date', $date)
            ->whereIn('status', [ProductStatus::Scheduled->value, ProductStatus::Live->value])
            ->count();

        if ($taken >= $cap) {
            throw ProductRuleViolation::launchDate('launch_cap_reached', "You can launch at most {$cap} products on one day.");
        }
    }
}
