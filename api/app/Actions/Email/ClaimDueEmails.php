<?php

namespace App\Actions\Email;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Takes up to N due outbox rows for one worker. One short transaction, no network call: rows are picked
 * `FOR UPDATE SKIP LOCKED` inside a materialised CTE (so the planner cannot re-run the LIMIT and touch more rows), marked
 * `sending` with a lease in `next_attempt_at`, and their attempt is counted. The transaction commits before any send.
 *
 * A claim is identified by `(id, attempts)`: every claim increments `attempts`, so a re-claim after a lease expiry changes it.
 * Lock order: this table only.
 */
class ClaimDueEmails
{
    /** How many lease-expired rows at their last attempt the last `handle()` call failed instead of claiming (they must be surfaced). */
    public int $exhausted = 0;

    /** @return list<array{id: string, attempts: int}> claimed rows in due order */
    public function handle(CarbonImmutable $now, int $limit): array
    {
        $moment = $now->utc()->format('Y-m-d H:i:s.uP');
        $lease = $now->addMinutes((int) config('launchpad.outbox.lease_minutes'))->utc()->format('Y-m-d H:i:s.uP');
        $max = (int) config('launchpad.outbox.max_attempts');
        $this->exhausted = 0;

        return DB::transaction(function () use ($moment, $lease, $limit, $max): array {
            // The WHERE repeats the predicate of email_messages_due_index, and ORDER BY is the index order, so no Sort node.
            // The due time is selected as sortable text: a CTE scan keeps the picked order in practice, but the code does not rely on it.
            /** @var list<object{id: string, status: string, attempts: int|string, due: string}> $picked */
            $picked = DB::select(
                "WITH picked AS MATERIALIZED (
                    SELECT id, status, attempts, next_attempt_at FROM email_messages
                    WHERE status IN ('queued', 'sending') AND next_attempt_at <= ?
                    ORDER BY next_attempt_at, id
                    LIMIT ?
                    FOR UPDATE SKIP LOCKED
                )
                SELECT id, status, attempts, to_char(next_attempt_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS due FROM picked",
                [$moment, $limit],
            );
            usort($picked, fn ($a, $b) => [$a->due, $a->id] <=> [$b->due, $b->id]);

            // A lease-expired row that already used its last attempt has nothing left to try: it fails instead of being claimed again.
            $exhausted = [];
            $claimable = [];
            foreach ($picked as $row) {
                if ($row->status === 'sending' && (int) $row->attempts >= $max) {
                    $exhausted[] = $row->id;
                } else {
                    $claimable[] = $row->id;
                }
            }

            if ($exhausted !== []) {
                $this->exhausted = count($exhausted);
                DB::table('email_messages')->whereIn('id', $exhausted)->update(['status' => 'failed', 'next_attempt_at' => null, 'last_error' => 'lease_expired', 'updated_at' => $moment]);
            }

            if ($claimable === []) {
                return [];
            }

            // The rows are ours (locked above), so a plain id list is safe here.
            $attempts = [];
            /** @var list<object{id: string, attempts: int|string}> $updated */
            $updated = DB::select(
                "UPDATE email_messages SET status = 'sending', attempts = attempts + 1, next_attempt_at = ?, updated_at = ?
                 WHERE id IN (".implode(', ', array_fill(0, count($claimable), '?')).') RETURNING id, attempts',
                [$lease, $moment, ...$claimable],
            );
            foreach ($updated as $row) {
                $attempts[$row->id] = (int) $row->attempts;
            }

            return array_map(fn (string $id) => ['id' => $id, 'attempts' => $attempts[$id]], $claimable);
        });
    }

    /**
     * Gives unsent claims back (the run stopped on its breaker or its budget): `queued`, due now, with the attempt of
     * the claim taken off again. A row someone re-claimed meanwhile has another `attempts` and is left alone.
     *
     * @param  list<array{id: string, attempts: int}>  $claims
     * @return int rows released
     */
    public function release(array $claims, CarbonImmutable $now): int
    {
        $moment = $now->utc()->format('Y-m-d H:i:s.uP');
        $released = 0;

        foreach ($claims as $claim) {
            $released += DB::affectingStatement(
                "UPDATE email_messages SET status = 'queued', attempts = greatest(attempts - 1, 0), next_attempt_at = ?, updated_at = ?
                 WHERE id = ? AND status = 'sending' AND attempts = ?",
                [$moment, $moment, $claim['id'], $claim['attempts']],
            );
        }

        return $released;
    }
}
