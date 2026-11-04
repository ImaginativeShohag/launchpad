<?php

namespace App\Console\Commands;

use App\Jobs\DeleteMemberComments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The safety net for DeleteAccount's after-commit job: if the dispatch failed (queue or cache down) or the job ran
 * out of tries, a deleted member's comments would keep inflating `comments_count` and `replies_count` for ever. Every
 * hour this finds deleted members that still own comments that were not erased and dispatches the (idempotent, unique)
 * job again.
 */
class SweepDeletedMemberCommentsCommand extends Command
{
    protected $signature = 'launchpad:sweep-deleted-member-comments';

    protected $description = 'Re-dispatch the comment clean-up for deleted members that still have comments that were not erased';

    public function handle(): int
    {
        $userIds = DB::table('comments')
            ->join('users', 'users.id', '=', 'comments.user_id')
            ->whereNotNull('users.deleted_at')
            ->whereNull('comments.deleted_at')
            ->select('comments.user_id')
            // Also deleted reporters whose open reports can be closed now (the job does both).
            ->union(DeleteMemberComments::closableReports()->select('r.reporter_id as user_id')->distinct())
            ->orderBy('user_id')
            ->pluck('user_id');

        $failed = 0;

        foreach ($userIds as $userId) {
            try {
                DeleteMemberComments::dispatch((string) $userId);
            } catch (Throwable $e) {
                $failed++;
                report($e);
            }
        }

        $this->info("Dispatched {$userIds->count()} clean-up job(s).");

        if ($failed > 0) {
            $this->error("{$failed} dispatch(es) failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
