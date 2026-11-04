<?php

namespace App\Actions\Notification;

use App\Enums\ProductStatus;
use App\Support\UlidFloor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The second net under the queued listeners: for the last 48 hours it re-derives the review outcomes, product-live
 * and comment notifications from state and records them with the same keys. Rows that exist are skipped by the unique
 * keys (a normal run creates nothing); a lost notification or a lost outbox row is filled in.
 *
 * Chunked by primary key, 200 per chunk, ids only (flat memory). Every item has its own try/catch: a failure is reported,
 * counted and the loop continues.
 */
class Reconcile
{
    public const WINDOW_HOURS = 48;

    /**
     * The `email_messages` retention window (Phase C) must never be shorter than this: a purged `sent` row frees its
     * idempotency key, and a sweep inside its window would then queue the mail a second time.
     */
    public const MIN_EMAIL_RETENTION_HOURS = self::WINDOW_HOURS + 24;

    public const CHUNK = 200;

    public function __construct(private readonly RecordNotification $record, private readonly int $chunk = self::CHUNK) {}

    /** @return array{found: int, created: int, failed: int} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $cutoff = $now->subHours(self::WINDOW_HOURS);
        $floor = strtolower(UlidFloor::at($cutoff));
        $moment = $cutoff->utc()->format('Y-m-d H:i:s.uP');
        $result = ['found' => 0, 'created' => 0, 'failed' => 0];

        // 1. Review outcomes: every review row of the window (each row is a fact; a withdraw and resubmit makes a new one).
        $this->sweep(
            fn (string $after): array => DB::table('product_reviews')->where('id', '>=', $floor)->where('id', '>', $after)
                ->where('created_at', '>=', $moment)->orderBy('id')->limit($this->chunk)->pluck('id')->all(),
            fn (string $id): array => array_filter([NotificationDrafts::reviewOutcome($id)]),
            $result,
        );

        // 2. Products that went live in the window (index: status, launched_at, id).
        $this->sweep(
            fn (string $after): array => DB::table('products')->where('status', ProductStatus::Live->value)->where('launched_at', '>=', $moment)
                ->whereNull('deleted_at')->where('id', '>', $after)->orderBy('id')->limit($this->chunk)->pluck('id')->all(),
            fn (string $id): array => array_filter([NotificationDrafts::productLive($id)]),
            $result,
        );

        // 3. Comments of the window that still count.
        $this->sweep(
            fn (string $after): array => DB::table('comments')->where('id', '>=', $floor)->where('id', '>', $after)->where('created_at', '>=', $moment)
                ->whereNull('removed_at')->whereNull('deleted_at')->orderBy('id')->limit($this->chunk)->pluck('id')->all(),
            fn (string $id): array => NotificationDrafts::comment($id),
            $result,
        );

        return $result;
    }

    /**
     * @param  callable(string): array<array-key, mixed>  $nextIds  the ids after a cursor, at most one chunk
     * @param  callable(string): list<NotificationDraft>  $drafts
     * @param  array{found: int, created: int, failed: int}  $result
     */
    private function sweep(callable $nextIds, callable $drafts, array &$result): void
    {
        $after = '';

        do {
            $ids = array_values(array_map(fn (mixed $id) => (string) $id, $nextIds($after)));

            foreach ($ids as $id) {
                try {
                    foreach ($drafts($id) as $draft) {
                        $result['found']++;
                        if ($this->record->handle($draft)) {
                            $result['created']++;
                        }
                    }
                } catch (Throwable $e) {
                    $result['failed']++;
                    report($e);
                }
            }

            $after = $ids === [] ? $after : (string) end($ids);
        } while (count($ids) === $this->chunk);
    }
}
