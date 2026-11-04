<?php

namespace App\Actions\AntiCheat;

use App\Enums\SettingKey;
use App\Models\RiskSignal;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Everything staff need to judge one member: profile basics, the score and where it comes from, how they vote, how often
 * they sign in from how many devices, who shares a device or IP with them and the latest signals. Read-only, a handful of
 * set-based queries; no lock. Counts and 8-character fingerprints only: never an e-mail, a hash, an IP or a user agent.
 * A deleted member is the anonymised row they were turned into.
 */
final class RiskMemberSummary
{
    public const SIGNALS_FOR_SCORE = 500;

    public const LATEST_SIGNALS = 20;

    public const LINKED = 50;

    public function __construct(private readonly Settings $settings, private readonly ScoreCalculator $calculator) {}

    /**
     * @return array{user: User, breakdown: array<string, array{contribution: int, open: int, confirmed: int, dismissed: int}>, votes: array<string, mixed>, sign_ins: array<string, int>, linked_accounts: list<array{id: string, handle: string, via: string}>, signals: Collection<int, RiskSignal>}|null
     */
    public function for(string $userId, CarbonImmutable $now): ?array
    {
        if (! Str::isUlid($userId)) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        $decay = $this->settings->integer(SettingKey::AnticheatDecayDays);
        // The breakdown covers the latest SIGNALS_FOR_SCORE (500) signals; ScoreUsers reads them all, so a member with more
        // than that has a stored score the breakdown can only approximate. Far beyond any real case (signals dedupe per day).
        $scored = RiskSignal::query()->where('user_id', $userId)->where('weight', '>', 0)
            ->orderByDesc('detected_at')->orderByDesc('id')->limit(self::SIGNALS_FOR_SCORE)
            ->get(['kind', 'weight', 'detected_at', 'outcome'])
            ->map(fn (RiskSignal $s) => new ScoredSignal($s->kind, $s->weight, CarbonImmutable::instance($s->detected_at), $s->outcome));

        $signals = RiskSignal::query()->where('user_id', $userId)->with(['product', 'reviewer'])
            ->orderByDesc('detected_at')->orderByDesc('id')->limit(self::LATEST_SIGNALS)->get();
        $signals->each(fn (RiskSignal $s) => $s->setRelation('user', $user));

        return [
            'user' => $user,
            'breakdown' => $this->calculator->breakdown($scored, $now, $decay),
            'votes' => $this->votes($userId),
            'sign_ins' => $this->signIns($userId, $now),
            'linked_accounts' => $this->linked($userId, $now),
            'signals' => $signals,
        ];
    }

    /** @return array<string, mixed> */
    private function votes(string $userId): array
    {
        $row = Sql::select(
            "SELECT COUNT(*) FILTER (WHERE removed_at IS NULL AND status IN ('counted', 'flagged')) AS active,
                    COUNT(*) FILTER (WHERE removed_at IS NULL AND status = 'flagged') AS flagged,
                    COUNT(*) FILTER (WHERE status = 'voided') AS voided,
                    COUNT(*) FILTER (WHERE removed_at IS NOT NULL AND status <> 'voided') AS withdrawn
             FROM votes WHERE user_id = ?",
            [$userId],
        )[0];

        $makers = Sql::select(
            'SELECT p.maker_id, COUNT(*) AS n FROM votes v JOIN products p ON p.id = v.product_id'
            .' WHERE v.user_id = ? AND '.Sql::activeVote('v').' GROUP BY p.maker_id ORDER BY n DESC, p.maker_id',
            [$userId],
        );
        $active = (int) $row->active;

        return [
            'active' => $active,
            'flagged' => (int) $row->flagged,
            'voided' => (int) $row->voided,
            'withdrawn' => (int) $row->withdrawn,
            'distinct_makers' => count($makers),
            'top_maker_share' => $active === 0 || $makers === [] ? 0.0 : round((int) $makers[0]->n / $active, 4),
        ];
    }

    /**
     * Distinct devices and IP groups behind the member's sign-ins; counts only, never a value.
     *
     * @return array{devices_24h: int, devices_7d: int, ip_groups_24h: int, ip_groups_7d: int}
     */
    private function signIns(string $userId, CarbonImmutable $now): array
    {
        $day = Sql::ts($now->subDay());
        $week = Sql::ts($now->subDays(7));
        $row = Sql::select(
            "SELECT COUNT(DISTINCT device_hash) FILTER (WHERE created_at >= ?) AS devices_24h,
                    COUNT(DISTINCT device_hash) FILTER (WHERE created_at >= ?) AS devices_7d,
                    COUNT(DISTINCT ip_hash) FILTER (WHERE created_at >= ?) AS ip_groups_24h,
                    COUNT(DISTINCT ip_hash) FILTER (WHERE created_at >= ?) AS ip_groups_7d
             FROM auth_events WHERE user_id = ? AND event IN ('signup', 'login_succeeded') AND created_at >= ?",
            [$day, $week, $day, $week, $userId, $week],
        )[0];

        return [
            'devices_24h' => (int) $row->devices_24h, 'devices_7d' => (int) $row->devices_7d,
            'ip_groups_24h' => (int) $row->ip_groups_24h, 'ip_groups_7d' => (int) $row->ip_groups_7d,
        ];
    }

    /**
     * Members who voted from the same device (or IP) as this one within the last 48 hours: id and handle, never the value.
     * A purged hash links nobody.
     *
     * @return list<array{id: string, handle: string, via: string}>
     */
    private function linked(string $userId, CarbonImmutable $now): array
    {
        $since = Sql::ts($now->subHours(48));
        $linked = [];

        foreach (['device' => 'device_hash', 'ip' => 'ip_hash'] as $via => $column) {
            $rows = Sql::select(
                "SELECT DISTINCT u.id, u.handle FROM votes a JOIN votes b ON b.{$column} = a.{$column} AND b.user_id <> a.user_id"
                .' JOIN users u ON u.id = b.user_id'
                ." WHERE a.user_id = ? AND a.{$column} IS NOT NULL AND a.voted_at >= ? AND b.voted_at >= ? AND u.deleted_at IS NULL"
                .' ORDER BY u.id LIMIT '.self::LINKED,
                [$userId, $since, $since],
            );

            foreach ($rows as $row) {
                $linked[] = ['id' => (string) $row->id, 'handle' => (string) $row->handle, 'via' => $via];
            }
        }

        usort($linked, fn (array $a, array $b) => [$a['id'], $a['via']] <=> [$b['id'], $b['via']]);

        return array_slice($linked, 0, self::LINKED);
    }
}
