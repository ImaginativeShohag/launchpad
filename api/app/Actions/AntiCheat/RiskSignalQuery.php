<?php

namespace App\Actions\AntiCheat;

use App\Models\RiskSignal;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The staff risk-signal list. Each status has one ordering and one partial index that serves it (all end in `id DESC`):
 * open `weight, detected_at, id` (risk_signals_open_queue_index), reviewed `reviewed_at, id` (risk_signals_reviewed_index),
 * all `detected_at, id` (risk_signals_detected_index). The status predicate is written as the index's own text. Related rows
 * are eager-loaded once each, so a page costs the same number of queries whatever its length.
 */
final class RiskSignalQuery
{
    /**
     * @param  'open'|'reviewed'|'all'  $status
     * @param  array{kind: ?string, user: ?string, product: ?string, min_weight: ?int, from: ?string, to: ?string}  $filters
     * @return CursorPaginator<int, RiskSignal>
     */
    public function page(string $status, array $filters, int $perPage): CursorPaginator
    {
        return $this->query($status, $filters)->cursorPaginate($perPage);
    }

    /**
     * @param  'open'|'reviewed'|'all'  $status
     * @param  array{kind: ?string, user: ?string, product: ?string, min_weight: ?int, from: ?string, to: ?string}  $filters
     * @return Builder<RiskSignal>
     */
    public function query(string $status, array $filters): Builder
    {
        $query = RiskSignal::query()->with(['user', 'product', 'reviewer']);

        match ($status) {
            'reviewed' => $query->whereRaw('risk_signals.reviewed_at IS NOT NULL')->orderByDesc('reviewed_at')->orderByDesc('id'),
            'all' => $query->orderByDesc('detected_at')->orderByDesc('id'),
            default => $query->whereRaw('risk_signals.reviewed_at IS NULL')->orderByDesc('weight')->orderByDesc('detected_at')->orderByDesc('id'),
        };

        return $query
            ->when($filters['kind'] !== null, fn (Builder $q) => $q->where('kind', $filters['kind']))
            ->when($filters['user'] !== null, fn (Builder $q) => $q->where('user_id', $filters['user']))
            ->when($filters['product'] !== null, fn (Builder $q) => $q->where('product_id', $filters['product']))
            ->when($filters['min_weight'] !== null, fn (Builder $q) => $q->where('weight', '>=', $filters['min_weight']))
            ->when($filters['from'] !== null, fn (Builder $q) => $q->where('detected_at', '>=', CarbonImmutable::parse((string) $filters['from'], 'UTC')->startOfDay()))
            ->when($filters['to'] !== null, fn (Builder $q) => $q->where('detected_at', '<', CarbonImmutable::parse((string) $filters['to'], 'UTC')->addDay()->startOfDay()));
    }
}
