<?php

namespace App\Actions\Report;

use App\Enums\CommentState;
use App\Enums\ProductStatus;
use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What staff read: the open queue (one row per reported target), one target in full, and the closed history.
 * Reporter identity is for staff only; nothing here is public.
 */
class ReportQueue
{
    /** The queue is meant to be drained, so it is a fixed list, not a paged one (the order moves as reports arrive). */
    public const LIMIT = 100;

    /** Of those, the top by ranking, and the rest is a lane for the OLDEST remaining targets (see open()). */
    public const TOP = 70;

    public const EXCERPT = 140;

    public const CLOSED_IN_DETAIL = 50;

    public const OPEN_IN_DETAIL = 200;

    /** A reporter with at least this many decided reports and a reliability below the floor is a habitual false reporter. */
    public const MIN_DECIDED = 3;

    public const RELIABILITY_FLOOR = '0.25';

    /**
     * The open queue, one row per reported target. THE RULE (the final one):
     *
     * - Only reports of eligible reporters (verified, not suspended, not deleted) exist for the queue; a target whose
     *   reports are all from anyone else is absent.
     * - A reporter's reliability is (upheld + 1) / (decided + 2) over their closed reports that a STAFF member handled:
     *   resolved or dismissed with `handled_by` set count (a system close, `handled_by` null, says nothing about the
     *   reporter and weighs zero both ways); a new reporter is 0.5.
     * - A reporter with at least 3 decided reports and a reliability below 0.25 counts for NOTHING in the ranking: weight
     *   0 in the score and not counted in `open_reports` (a ring of fake accounts cannot out-rank real reports by sheer
     *   number). Their reports are still listed in the detail view and still closed by a resolve.
     * - Ranking: `open_reports` desc, `score` (summed weights, rounded to 6 places in SQL, so equal sums tie) desc, the
     *   oldest report asc, target id asc.
     * - At most 100 rows: the top 70 by that ranking plus up to 30 of the OLDEST remaining targets (by oldest report),
     *   so a flood of high-count targets cannot starve a lone real report forever. The 100 are displayed in the ranking
     *   order. `total` is the number of distinct targets with an eligible open report; `truncated` is total > listed.
     * - Reasons count every eligible open report of the target (even the weightless ones).
     *
     * Targets that are removed, deleted, taken down or hidden stay listed: staff must be able to close them. A target
     * that cannot be read at all (a draft, which cannot be reported anyway) is skipped.
     *
     * @param  'comment'|'product'|null  $type
     */
    public function open(?string $type): OpenQueue
    {
        $bindings = [];
        $typeFilter = '';

        if ($type !== null) {
            $typeFilter = 'AND r.reportable_type = ?';
            $bindings[] = $type;
        }

        $floor = self::RELIABILITY_FLOOR;
        $minDecided = self::MIN_DECIDED;

        /** @var list<object{reportable_type: string, reportable_id: string, open_reports: int, score: string, oldest_report_at: string, total: int}> $rows */
        $rows = DB::select(<<<SQL
            WITH reliability AS (
                SELECT reporter_id,
                       COUNT(*) FILTER (WHERE status = 'resolved' AND handled_by IS NOT NULL) AS upheld,
                       COUNT(*) FILTER (WHERE status IN ('resolved', 'dismissed') AND handled_by IS NOT NULL) AS decided
                FROM reports
                WHERE status <> 'open' AND reporter_id IN (SELECT reporter_id FROM reports WHERE status = 'open')
                GROUP BY reporter_id
            ), weighted AS (
                SELECT r.reportable_type, r.reportable_id, r.created_at,
                       (COALESCE(rel.decided, 0) >= {$minDecided}
                        AND (COALESCE(rel.upheld, 0) + 1)::numeric / (COALESCE(rel.decided, 0) + 2) < {$floor}) AS ring,
                       (COALESCE(rel.upheld, 0) + 1)::numeric / (COALESCE(rel.decided, 0) + 2) AS reliability
                FROM reports r
                JOIN users u ON u.id = r.reporter_id AND u.email_verified_at IS NOT NULL AND u.suspended_at IS NULL AND u.deleted_at IS NULL
                LEFT JOIN reliability rel ON rel.reporter_id = r.reporter_id
                WHERE r.status = 'open' {$typeFilter}
            ), targets AS (
                SELECT w.reportable_type, w.reportable_id,
                       COUNT(*) FILTER (WHERE NOT w.ring) AS open_reports,
                       ROUND(COALESCE(SUM(w.reliability) FILTER (WHERE NOT w.ring), 0), 6) AS score,
                       MIN(w.created_at) AS oldest_report_at
                FROM weighted w
                GROUP BY w.reportable_type, w.reportable_id
            ), ranked AS (
                SELECT t.*,
                       ROW_NUMBER() OVER (ORDER BY open_reports DESC, score DESC, oldest_report_at ASC, reportable_id ASC, reportable_type ASC) AS rk,
                       COUNT(*) OVER () AS total
                FROM targets t
            ), lane AS (
                SELECT ranked.*, ROW_NUMBER() OVER (ORDER BY oldest_report_at ASC, reportable_id ASC, reportable_type ASC) AS lane_rk
                FROM ranked WHERE rk > ?
            ), picked AS (
                SELECT reportable_type, reportable_id, open_reports, score, oldest_report_at, total FROM ranked WHERE rk <= ?
                UNION ALL
                SELECT reportable_type, reportable_id, open_reports, score, oldest_report_at, total FROM lane WHERE lane_rk <= ?
            )
            SELECT * FROM picked
            ORDER BY open_reports DESC, score DESC, oldest_report_at ASC, reportable_id ASC, reportable_type ASC
            SQL, [...$bindings, self::TOP, self::TOP, self::LIMIT - self::TOP]);

        if ($rows === []) {
            return new OpenQueue(collect(), 0);
        }

        $total = (int) $rows[0]->total;

        $ids = array_map(fn (object $row) => $row->reportable_id, $rows);
        $reasons = $this->reasonCounts($ids);
        $cards = $this->queueCards(
            array_values(array_map(fn (object $row) => $row->reportable_id, array_filter($rows, fn (object $row) => $row->reportable_type === 'comment'))),
            array_values(array_map(fn (object $row) => $row->reportable_id, array_filter($rows, fn (object $row) => $row->reportable_type === 'product'))),
        );

        $queue = collect();

        foreach ($rows as $row) {
            $target = $cards[$row->reportable_type.':'.$row->reportable_id] ?? null;

            if ($target === null) {
                continue;
            }

            $queue->push([
                'type' => $row->reportable_type,
                'target' => $target,
                'open_reports' => (int) $row->open_reports,
                'reasons' => $reasons[$row->reportable_type.':'.$row->reportable_id] ?? [],
                'score' => round((float) $row->score, 6),
                'oldest_report_at' => Carbon::parse($row->oldest_report_at),
            ]);
        }

        return new OpenQueue($queue, $total);
    }

    /**
     * One target for staff: the full comment body even when removed, the author, every open report (with whether its
     * reporter still counts) and the latest closed ones.
     *
     * @param  'comment'|'product'  $type
     * @return array{type: string, target: array<string, mixed>, open_reports: Collection<int, Report>, open_total: int, closed_reports: Collection<int, Report>}|null null: the uniform 404 (unknown, a draft product, a comment on one)
     */
    public function detail(string $type, string $targetId): ?array
    {
        $target = $type === 'comment' ? $this->commentDetail($targetId) : $this->productDetail($targetId);

        if ($target === null) {
            return null;
        }

        $reports = fn () => Report::query()->where('reportable_type', $type)->where('reportable_id', $targetId)->with(['reporter', 'handler']);

        return [
            'type' => $type,
            'target' => $target,
            // The oldest 200 (a target cannot be flooded into an unreadable response); `open_total` says how many exist.
            'open_reports' => $reports()->open()->orderBy('created_at')->orderBy('id')->limit(self::OPEN_IN_DETAIL)->get(),
            'open_total' => $reports()->open()->count(),
            'closed_reports' => $reports()->whereRaw("reports.status <> 'open'")->orderByDesc('handled_at')->orderByDesc('id')->limit(self::CLOSED_IN_DETAIL)->get(),
        ];
    }

    /**
     * Closed reports, newest first, keyset (handled_at, id), served by the partial index reports_history_index.
     *
     * @param  'comment'|'product'|null  $type
     * @return CursorPaginator<int, Report>
     */
    public function history(?string $type, int $perPage): CursorPaginator
    {
        return Report::query()
            // Written out so it matches the index predicate `status <> 'open'` textually.
            ->whereRaw("reports.status <> 'open'")
            ->when($type !== null, fn ($query) => $query->where('reportable_type', $type))
            ->with(['reporter', 'handler'])
            ->orderByDesc('handled_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /** Whether a report still counts: its reporter is verified, not suspended and not deleted. */
    public static function reporterEligible(User $reporter): bool
    {
        return $reporter->email_verified_at !== null && $reporter->isAvailable();
    }

    /**
     * @param  list<string>  $targetIds
     * @return array<string, array<string, int>> "type:id" => reason => count, eligible open reports only
     */
    private function reasonCounts(array $targetIds): array
    {
        $rows = DB::table('reports as r')
            ->join('users as u', fn ($join) => $join->on('u.id', '=', 'r.reporter_id')
                ->whereNotNull('u.email_verified_at')->whereNull('u.suspended_at')->whereNull('u.deleted_at'))
            ->where('r.status', ReportStatus::Open->value)
            ->whereIn('r.reportable_id', $targetIds)
            ->groupBy('r.reportable_type', 'r.reportable_id', 'r.reason')
            ->orderBy('r.reportable_type')->orderBy('r.reportable_id')->orderBy('r.reason')
            ->get(['r.reportable_type', 'r.reportable_id', 'r.reason', DB::raw('COUNT(*) as n')]);

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->reportable_type.':'.$row->reportable_id][$row->reason] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * The compact target summaries of a queue page, two queries at most.
     *
     * @param  list<string>  $commentIds
     * @param  list<string>  $productIds
     * @return array<string, array<string, mixed>> "type:id" => summary
     */
    private function queueCards(array $commentIds, array $productIds): array
    {
        $cards = [];

        if ($commentIds !== []) {
            foreach (Comment::query()->whereIn('id', $commentIds)->with(['author', 'product' => fn ($products) => $products->withTrashed()])->get() as $comment) {
                if ($comment->product->status === ProductStatus::Draft) {
                    continue;
                }

                $state = $comment->state();
                $cards['comment:'.$comment->getKey()] = [
                    'id' => $comment->getKey(),
                    'state' => $state->value,
                    // Kept for a removed comment (staff need the evidence); a deleted one has no text to show.
                    'body_excerpt' => $state === CommentState::Deleted ? '' : mb_substr($comment->body, 0, self::EXCERPT),
                    'author' => ['id' => $comment->author->getKey(), 'handle' => $comment->author->handle],
                    'product' => ['id' => $comment->product->getKey(), 'slug' => $comment->product->slug, 'name' => $comment->product->name],
                ];
            }
        }

        if ($productIds !== []) {
            foreach (Product::query()->whereIn('id', $productIds)->where('status', '<>', ProductStatus::Draft->value)->with('maker')->get() as $product) {
                $cards['product:'.$product->getKey()] = [
                    'id' => $product->getKey(),
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'status' => $product->status->value,
                    'maker' => ['id' => $product->maker->getKey(), 'handle' => $product->maker->handle],
                ];
            }
        }

        return $cards;
    }

    /** @return array<string, mixed>|null */
    private function commentDetail(string $id): ?array
    {
        $comment = Comment::query()->whereKey($id)->with(['author', 'remover', 'product' => fn ($products) => $products->withTrashed()])->first();

        if ($comment === null || $comment->product->status === ProductStatus::Draft) {
            return null;
        }

        return [
            'id' => $comment->getKey(),
            'parent_id' => $comment->parent_id,
            'state' => $comment->state()->value,
            // The stored text, even for a removed comment: this is the evidence staff judge.
            'body' => $comment->body,
            'author' => ['id' => $comment->author->getKey(), 'handle' => $comment->author->handle, 'name' => $comment->author->name, 'is_suspended' => $comment->author->isSuspended()],
            'product' => ['id' => $comment->product->getKey(), 'slug' => $comment->product->slug, 'name' => $comment->product->name, 'status' => $comment->product->status->value],
            'created_at' => $comment->created_at,
            'removed_at' => $comment->removed_at,
            'removed_by' => $comment->remover === null ? null : ['id' => $comment->remover->getKey(), 'handle' => $comment->remover->handle],
            'removed_reason' => $comment->removed_reason,
        ];
    }

    /** @return array<string, mixed>|null */
    private function productDetail(string $id): ?array
    {
        $product = Product::query()->whereKey($id)->where('status', '<>', ProductStatus::Draft->value)->with('maker')->first();

        if ($product === null) {
            return null;
        }

        return [
            'id' => $product->getKey(),
            'slug' => $product->slug,
            'name' => $product->name,
            'tagline' => $product->tagline,
            'status' => $product->status->value,
            'launch_date' => $product->launch_date?->toDateString(),
            'takedown_reason' => $product->takedown_reason,
            'maker' => ['id' => $product->maker->getKey(), 'handle' => $product->maker->handle, 'name' => $product->maker->name, 'is_suspended' => $product->maker->isSuspended()],
        ];
    }
}
