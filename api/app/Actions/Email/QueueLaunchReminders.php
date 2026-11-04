<?php

namespace App\Actions\Email;

use App\Actions\Notification\NotificationDrafts;
use App\Actions\Notification\RecordNotification;
use App\Enums\ProductStatus;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The day before a launch (UTC), from `digest.daily_send_at_utc` until midnight, tells the maker of every scheduled product
 * launching tomorrow. Once per (product, launch date): the date is in the bell key and the mail key, so a re-run creates
 * nothing and a reschedule to another date creates one more reminder when that date's window opens.
 *
 * Chunked by product id (flat memory), one try/catch per product: a failure is reported and counted and the loop goes on.
 * The command turns a non-zero `failed` into a non-zero exit. The clock is read once per run (and may be passed in).
 */
class QueueLaunchReminders
{
    public const CHUNK = 200;

    /** True when the last run was before the day's send time, so it did nothing. */
    public bool $outsideWindow = false;

    public function __construct(
        private readonly RecordNotification $record,
        private readonly Settings $settings,
        private readonly int $chunk = self::CHUNK,
    ) {}

    /** @return array{found: int, created: int, failed: int} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $result = ['found' => 0, 'created' => 0, 'failed' => 0];

        // Window: today at the send time until midnight; "tomorrow" is the launch date D, today is D-1.
        $opensAt = CarbonImmutable::parse($now->toDateString().' '.$this->settings->timeOfDay(SettingKey::DigestDailySendAtUtc), 'UTC');
        $this->outsideWindow = $now->lt($opensAt);

        if ($this->outsideWindow) {
            return $result;
        }

        $launchDate = $now->addDay()->toDateString();
        $after = '';

        do {
            $products = Product::query()
                ->where('status', ProductStatus::Scheduled->value)
                ->where('launch_date', $launchDate)
                ->whereNull('deleted_at')
                ->whereExists(fn ($maker) => $maker->selectRaw('1')->from('users')->whereColumn('users.id', 'products.maker_id')->whereNull('users.deleted_at')->whereNull('users.suspended_at'))
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit($this->chunk)
                ->get();

            foreach ($products as $product) {
                try {
                    $draft = NotificationDrafts::launchReminder($product);
                    $result['found']++;
                    if ($this->record->handle($draft)) {
                        $result['created']++;
                    }
                } catch (Throwable $e) {
                    $result['failed']++;
                    report($e);
                }
            }

            $after = (string) $products->last()?->getKey();
        } while ($products->count() === $this->chunk);

        return $result;
    }
}
