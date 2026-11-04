<?php

namespace App\Actions\AntiCheat;

use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;

/**
 * Members by risk, highest score first (`risk_score DESC, id DESC`, served by the partial index users_risk_score_index,
 * whose own predicate is written out). Deleted members are left out. The open-signal and flagged-vote counts of a page
 * come from two grouped queries over the page's ids.
 */
final class RiskMemberQuery
{
    /**
     * @return CursorPaginator<int, User>
     */
    public function page(int $minScore, ?bool $suspended, int $perPage): CursorPaginator
    {
        $page = User::query()
            ->whereRaw('users.risk_score > 0')
            ->where('risk_score', '>=', $minScore)
            ->whereNull('deleted_at')
            ->when($suspended === true, fn ($q) => $q->whereNotNull('suspended_at'))
            ->when($suspended === false, fn ($q) => $q->whereNull('suspended_at'))
            ->orderByDesc('risk_score')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);

        $ids = collect($page->items())->map(fn (User $u) => $u->id)->all();

        if ($ids === []) {
            return $page;
        }

        $open = DB::table('risk_signals')->whereIn('user_id', $ids)->whereRaw('reviewed_at IS NULL')->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) AS n')->pluck('n', 'user_id');
        $flagged = DB::table('votes')->whereIn('user_id', $ids)->where('status', 'flagged')->whereNull('removed_at')->groupBy('user_id')
            ->selectRaw('user_id, COUNT(*) AS n')->pluck('n', 'user_id');

        foreach ($page->items() as $user) {
            $user->setAttribute('open_signals', (int) ($open[$user->id] ?? 0));
            $user->setAttribute('flagged_votes', (int) ($flagged[$user->id] ?? 0));
        }

        return $page;
    }
}
