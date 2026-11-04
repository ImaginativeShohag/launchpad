<?php

namespace App\Actions\AntiCheat;

use App\Models\RiskSignal;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Turns the ids in a signal's `details` into what staff read: the members (with their score and state), the votes (status,
 * time, and 8-character device / IP fingerprints computed in SQL, `null` once the 90-day purge ran) and the product.
 * Each list is capped at 50 ids and costs one query, so the full 64-character hash never reaches PHP.
 *
 * `maker_id`, `shared_product_ids` and `auth_event_ids` stay ids: they are not resolved here.
 */
final class SignalEvidence
{
    public const MAX = Finding::MAX_IDS;

    /**
     * @return array{members: list<array<string, mixed>>, votes: list<array<string, mixed>>, product: array<string, mixed>|null}
     */
    public function resolve(RiskSignal $signal): array
    {
        $details = $signal->details;
        $memberIds = $this->ids($details['user_ids'] ?? []);

        if ($signal->user_id !== null) {
            array_unshift($memberIds, $signal->user_id);
        }

        $voteIds = $this->ids($details['vote_ids'] ?? []);

        if (isset($details['vote_id']) && is_string($details['vote_id'])) {
            $voteIds[] = $details['vote_id'];
        }

        $memberIds = array_slice(array_values(array_unique($memberIds)), 0, self::MAX);
        $voteIds = array_slice(array_values(array_unique($voteIds)), 0, self::MAX);

        $product = $signal->product;

        return [
            'members' => $this->members($memberIds),
            'votes' => $this->votes($voteIds),
            'product' => $product === null ? null : [
                'id' => $product->id, 'slug' => $product->slug, 'name' => $product->name,
                'launch_date' => $product->launch_date?->toDateString(),
            ],
        ];
    }

    /**
     * Only plain 26-character string ids can match a row, so anything else (a hand-edited details object) is dropped
     * before it reaches a query.
     *
     * @return list<string>
     */
    private function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn ($id) => is_string($id) && preg_match('/\A[0-9a-z]{26}\z/', $id) === 1));
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function members(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values(User::query()->whereIn('id', $ids)->orderBy('id')->get()->map(fn (User $u) => [
            'id' => $u->id, 'handle' => $u->handle, 'name' => $u->name, 'role' => $u->role->value, 'risk_score' => $u->risk_score,
            'created_at' => $u->created_at?->toIso8601String(), 'suspended' => $u->isSuspended(), 'deleted' => $u->isDeleted(),
        ])->all());
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function votes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_map(fn (object $row) => [
            'id' => $row->id, 'user_id' => $row->user_id, 'status' => $row->status,
            'voted_at' => CarbonImmutable::parse($row->voted_at, 'UTC')->toIso8601String(), 'removed' => $row->removed_at !== null,
            'device_fp' => $row->device_fp, 'ip_fp' => $row->ip_fp,
        ], Sql::select(
            'SELECT id, user_id, status, voted_at, removed_at, '.Sql::fingerprint('device_hash').' AS device_fp, '.Sql::fingerprint('ip_hash').' AS ip_fp'
            .' FROM votes WHERE id IN ('.Sql::placeholders($ids).') ORDER BY voted_at DESC, id DESC',
            $ids,
        ));
    }
}
