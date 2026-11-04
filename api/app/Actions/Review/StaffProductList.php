<?php

namespace App\Actions\Review;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Support\LikePattern;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Every product that has left the draft stage, latest change first (`updated_at DESC, id DESC`, served by
 * products_staff_list_index, or products_staff_status_index with a status filter). Drafts are the founder's private work and
 * never listed; soft-deleted rows are excluded by the model. `q` matches inside the name or at the start of the slug; the
 * pattern is bound and its wildcards are escaped.
 */
final class StaffProductList
{
    /** @return CursorPaginator<int, Product> */
    public function page(?ProductStatus $status, ?string $q, int $perPage): CursorPaginator
    {
        return Product::query()
            ->with(['maker', 'topics'])
            ->when(
                $status !== null,
                fn ($query) => $query->where('status', $status?->value),
                // A literal, so the partial index predicate is provable from the statement text.
                fn ($query) => $query->whereRaw("status <> 'draft'"),
            )
            ->when($q !== null, fn ($query) => $query->where(fn ($match) => $match
                ->whereRaw("lower(name) like ? escape '\\'", [LikePattern::contains((string) $q)])
                ->orWhereRaw("slug like ? escape '\\'", [LikePattern::prefix((string) $q)])))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
