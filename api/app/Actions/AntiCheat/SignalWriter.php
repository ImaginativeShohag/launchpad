<?php

namespace App\Actions\AntiCheat;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes one chunk of findings in its own transaction.
 *
 * Lock order: an INSERT takes `FOR KEY SHARE` on the product and user rows it references, in row order. Two inserts out of
 * id order, against `FreezeDay` (product rows `FOR UPDATE` in id order) or `DeleteAccount`, would be a lock cycle. So every
 * referenced product is locked `FOR KEY SHARE` in id order first, then every referenced user, and only then the insert runs.
 *
 * The insert is `ON CONFLICT (dedupe_key) DO NOTHING`: a rerun costs nothing, and an existing row (even a dismissed or
 * confirmed one) is never touched, so its first evidence and weight stay.
 */
final class SignalWriter
{
    private const BATCH = 200;

    /** How long a chunk may wait behind another session's lock (`FreezeDay`, an admin action) before it fails and is counted. */
    public const LOCK_TIMEOUT = '5s';

    /**
     * @param  list<Finding>  $findings
     * @return array<string, int> kind value => rows inserted
     */
    public function write(array $findings, CarbonImmutable $now): array
    {
        if ($findings === []) {
            return [];
        }

        return DB::transaction(function () use ($findings, $now): array {
            // Transaction-local: a wait behind a long lock fails this chunk (counted, retried next run) instead of hanging the run.
            DB::select('SELECT set_config(?, ?, true)', ['lock_timeout', self::LOCK_TIMEOUT]);

            $this->lock('products', array_values(array_unique(array_filter(array_map(fn (Finding $f) => $f->productId, $findings)))));
            $this->lock('users', array_values(array_unique(array_filter(array_map(fn (Finding $f) => $f->userId, $findings)))));

            $counts = [];

            foreach (array_chunk($findings, self::BATCH) as $batch) {
                $rows = [];
                $bindings = [];

                foreach ($batch as $finding) {
                    $rows[] = '(?, ?, ?, ?, ?, ?::jsonb, ?, ?)';
                    array_push(
                        $bindings,
                        (string) Str::ulid(),
                        $finding->kind->value,
                        $finding->userId,
                        $finding->productId,
                        $finding->weight,
                        json_encode($finding->details, JSON_THROW_ON_ERROR),
                        $finding->dedupeKey,
                        Sql::ts($now),
                    );
                }

                $inserted = Sql::select(
                    'INSERT INTO risk_signals (id, kind, user_id, product_id, weight, details, dedupe_key, detected_at) VALUES '
                    .implode(', ', $rows).' ON CONFLICT (dedupe_key) DO NOTHING RETURNING kind',
                    $bindings,
                );

                foreach ($inserted as $row) {
                    $counts[$row->kind] = ($counts[$row->kind] ?? 0) + 1;
                }
            }

            return $counts;
        });
    }

    /** @param  list<string>  $ids */
    private function lock(string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // No `deleted_at` filter: a soft-deleted row is still a foreign key target.
        Sql::select("SELECT id FROM {$table} WHERE id IN (".Sql::placeholders($ids).') ORDER BY id FOR KEY SHARE', $ids);
    }
}
