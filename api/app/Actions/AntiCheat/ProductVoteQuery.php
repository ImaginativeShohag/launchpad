<?php

namespace App\Actions\AntiCheat;

use App\Models\Product;
use App\Models\Vote;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * The votes of one product for staff, newest first (`voted_at DESC, id DESC`; the index (product_id, voted_at) serves the
 * leading columns, an incremental sort finishes the tie-break). The fingerprints are computed in SQL, so no stored hash
 * reaches PHP. Voters are eager-loaded in one query.
 */
final class ProductVoteQuery
{
    /**
     * @return CursorPaginator<int, Vote>
     */
    public function page(Product $product, ?string $status, ?string $voter, int $perPage): CursorPaginator
    {
        return Vote::query()
            ->select(['votes.id', 'votes.user_id', 'votes.status', 'votes.voted_at', 'votes.removed_at', 'votes.toggle_count'])
            ->selectRaw(Sql::fingerprint('votes.device_hash').' AS device_fp, '.Sql::fingerprint('votes.ip_hash').' AS ip_fp') // @phpstan-ignore argument.type
            ->where('votes.product_id', $product->id)
            ->when($status !== null, fn ($q) => $q->where('votes.status', $status))
            ->when($voter !== null, fn ($q) => $q->where('votes.user_id', $voter))
            ->with('user')
            ->orderByDesc('voted_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
