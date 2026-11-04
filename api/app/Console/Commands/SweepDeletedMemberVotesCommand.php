<?php

namespace App\Console\Commands;

use App\Jobs\VoidMemberVotes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * The safety net for DeleteAccount's after-commit job: if the dispatch failed (queue or cache down) or the job ran
 * out of tries, a deleted member's votes would stay counted for ever once their day freezes. Every hour this finds
 * deleted members that still hold active votes on unfrozen days and dispatches the (idempotent, unique) job again.
 */
class SweepDeletedMemberVotesCommand extends Command
{
    protected $signature = 'launchpad:sweep-deleted-member-votes';

    protected $description = 'Re-dispatch the vote clean-up for deleted members that still have counted votes on unfrozen days';

    public function handle(): int
    {
        $today = Date::now('UTC')->toDateString();

        $userIds = DB::table('votes')
            ->join('users', 'users.id', '=', 'votes.user_id')
            ->join('products', 'products.id', '=', 'votes.product_id')
            ->whereNotNull('users.deleted_at')
            ->whereNull('votes.removed_at')
            ->whereIn('votes.status', ['counted', 'flagged'])
            ->where('products.launch_date', '>=', $today)
            ->distinct()
            ->orderBy('votes.user_id')
            ->pluck('votes.user_id');

        $failed = 0;

        foreach ($userIds as $userId) {
            if (! VoidMemberVotes::dispatchSafely((string) $userId)) {
                $failed++;
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
