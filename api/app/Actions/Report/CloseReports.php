<?php

namespace App\Actions\Report;

use App\Enums\ReportStatus;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closing the open reports of one target. Reports are leaf rows, so they are locked last: the caller already holds
 * the product row (and the comment row) of the target, and calls lockOpen() after them.
 */
class CloseReports
{
    /**
     * The open report ids of a target, locked FOR UPDATE in id order. Run inside the caller's transaction.
     *
     * @return Collection<int, string>
     */
    public function lockOpen(string $type, string $targetId): Collection
    {
        /** @var Collection<int, string> $ids */
        $ids = DB::table('reports')
            ->where('reportable_type', $type)
            ->where('reportable_id', $targetId)
            ->where('status', ReportStatus::Open->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        return $ids;
    }

    /**
     * Closes the given (locked, open) reports. `$handler` null means the system closed them.
     *
     * @param  Collection<int, string>  $ids
     * @return int how many rows were closed
     */
    public function close(Collection $ids, ReportStatus $status, ?User $handler, ?string $note): int
    {
        if ($ids->isEmpty()) {
            return 0;
        }

        // Eloquent would truncate the instants to whole seconds; the history list is keyset-ordered by handled_at.
        $now = now()->utc()->format('Y-m-d H:i:s.uP');

        return DB::table('reports')->whereIn('id', $ids->all())->where('status', ReportStatus::Open->value)->update([
            'status' => $status->value,
            'handled_by' => $handler?->getKey(),
            'handled_at' => $now,
            'resolution_note' => $note,
            'updated_at' => $now,
        ]);
    }

    /** Locks and closes the open reports of a target in one go; the caller holds the target's locks already. */
    public function forTarget(string $type, string $targetId, ReportStatus $status, ?User $handler, ?string $note): int
    {
        return $this->close($this->lockOpen($type, $targetId), $status, $handler, $note);
    }
}
