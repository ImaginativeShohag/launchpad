<?php

namespace App\Jobs;

use App\Actions\Comment\DeleteComment;
use App\Actions\Report\CloseReports;
use App\Enums\ReportStatus;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Erases a deleted member's comments (the body is wiped and the product and thread counters are corrected) and then
 * closes their remaining open reports (see closeOwnReports()). Dispatched by
 * DeleteAccount AFTER its transaction committed, because every comment needs its product row and DeleteAccount holds the
 * member's user row (global lock order). Until it runs the reads already mask the member's comments, and the counters
 * stay inflated. Idempotent, so a retry only finishes what is left; the hourly sweep re-dispatches if the dispatch
 * failed or the job ran out of tries. Production needs an async queue and a running worker.
 */
class DeleteMemberComments implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const CHUNK = 100;

    public int $tries = 3;

    /** Below `retry_after` (90 s) so a hung run is not started twice; the unique lock expires if a worker dies. */
    public int $timeout = 60;

    public int $uniqueFor = 3600;

    /** Seconds of work per run, well under `$timeout`; a longer backlog continues in a fresh job (see handle()). */
    public float $budget = 45.0;

    public function __construct(public readonly string $userId) {}

    public function uniqueId(): string
    {
        return $this->userId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * Open reports of deleted reporters that can be closed now: the target has no OTHER open report from an eligible
     * reporter. (If there is one, the report stays: a resolve of the target closes its ineligible siblings together.)
     * The sweep uses the same query, so it never re-dispatches for reports that must stay.
     */
    public static function closableReports(): Builder
    {
        return DB::table('reports as r')
            ->join('users as gone', 'gone.id', '=', 'r.reporter_id')
            ->whereNotNull('gone.deleted_at')
            ->where('r.status', ReportStatus::Open->value)
            ->whereNotExists(fn ($sibling) => $sibling->select(DB::raw(1))->from('reports as s')
                ->join('users as su', fn ($join) => $join->on('su.id', '=', 's.reporter_id')
                    ->whereNotNull('su.email_verified_at')->whereNull('su.suspended_at')->whereNull('su.deleted_at'))
                ->whereColumn('s.reportable_type', 'r.reportable_type')->whereColumn('s.reportable_id', 'r.reportable_id')
                ->where('s.status', ReportStatus::Open->value)->whereColumn('s.id', '<>', 'r.id'));
    }

    public function handle(DeleteComment $delete, int $chunk = self::CHUNK): void
    {
        // Only for an account that really was deleted: a wrongly dispatched job must never touch a live member's comments.
        if (! DB::table('users')->where('id', $this->userId)->whereNotNull('deleted_at')->exists()) {
            return;
        }

        $afterId = '';
        $failure = null;
        $started = microtime(true);

        do {
            $ids = DB::table('comments')
                ->where('user_id', $this->userId)
                ->whereNull('deleted_at')
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id');

            foreach ($ids as $id) {
                try {
                    // Its own transaction per comment: one failed statement cannot poison the others.
                    $delete->forDeletedMember((string) $id);
                } catch (Throwable $e) {
                    report($e);
                    $failure ??= $e;
                }

                if (microtime(true) - $started > $this->budget) {
                    $this->continueInFreshJob();

                    return;
                }
            }

            // The cursor moves past a failing comment, so one bad row cannot keep the loop on the same chunk.
            $afterId = (string) $ids->last();
        } while ($ids->count() === $chunk);

        try {
            $this->closeOwnReports();
        } catch (Throwable $e) {
            report($e);
            $failure ??= $e;
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Over budget: hand the rest to a fresh job and return normally (no failure, no retry counted). The unique lock is
     * still held by THIS job, so a normal dispatch would be silently skipped; the bus dispatcher is used directly. The
     * lock is released when this job finishes, and a duplicate run is harmless (idempotent, row locks).
     */
    private function continueInFreshJob(): void
    {
        app(Dispatcher::class)->dispatch(new self($this->userId));
    }

    /**
     * The deleted member's remaining open reports are closed as dismissed by the system (`handled_by` null, so they
     * weigh nothing in anybody's reliability), but only those whose target has no other eligible open report. Their
     * text stays in the history. Report rows only (id order, leaf rows): no user or product row is locked.
     */
    private function closeOwnReports(): void
    {
        DB::transaction(function (): void {
            $mine = DB::table('reports')->where('reporter_id', $this->userId)->where('status', ReportStatus::Open->value)
                ->orderBy('id')->lockForUpdate()->pluck('id');

            if ($mine->isEmpty()) {
                return;
            }

            $closable = self::closableReports()->where('r.reporter_id', $this->userId)->whereIn('r.id', $mine->all())->pluck('r.id');

            app(CloseReports::class)->close($closable, ReportStatus::Dismissed, null, 'reporter_deleted');
        });
    }
}
