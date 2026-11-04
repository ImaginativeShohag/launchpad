<?php

namespace App\Actions\AntiCheat;

use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `users.risk_score` equal to ScoreCalculator over the member's signals.
 *
 * `handle` rewrites the score of a list of members in chunks of 500, one transaction per chunk. User rows are locked
 * `FOR NO KEY UPDATE SKIP LOCKED` in id order (a plain `FOR UPDATE` would conflict with the key-share locks that audit and
 * vote foreign keys take on a user row) and the pass never waits while it holds them: a member locked by someone else is
 * skipped and picked up by the next run. Only rows whose score changes are written (`risk_score <> new`), so a run that
 * changes nothing writes nothing, and `updated_at` is never touched. Deleted members are skipped; suspended ones are scored.
 */
class ScoreUsers
{
    public const CHUNK = 500;

    public function __construct(private readonly Settings $settings, private readonly ScoreCalculator $calculator) {}

    /**
     * @param  list<string>  $userIds
     * @return int members whose stored score changed
     */
    public function handle(array $userIds, CarbonImmutable $now, ?int $decayDays = null): int
    {
        $decayDays ??= $this->settings->integer(SettingKey::AnticheatDecayDays);
        $changed = 0;

        foreach (array_chunk(array_values(array_unique($userIds)), self::CHUNK) as $chunk) {
            // Merged only after the chunk's transaction committed.
            $changed += DB::transaction(fn (): int => $this->chunk($chunk, $now, $decayDays));
        }

        return $changed;
    }

    /**
     * Recomputes one member whose row the caller already holds locked, and returns the new score.
     */
    public function forLockedUser(string $userId, CarbonImmutable $now, ?int $decayDays = null): int
    {
        $decayDays ??= $this->settings->integer(SettingKey::AnticheatDecayDays);
        $score = $this->scores([$userId], $now, $decayDays)[$userId];

        DB::update('UPDATE users SET risk_score = ? WHERE id = ? AND risk_score <> ?', [$score, $userId, $score]);

        return $score;
    }

    /**
     * @param  list<string>  $chunk
     */
    private function chunk(array $chunk, CarbonImmutable $now, int $decayDays): int
    {
        $locked = array_map(fn (object $row) => $row->id, Sql::select(
            'SELECT id FROM users WHERE id IN ('.Sql::placeholders($chunk).') AND deleted_at IS NULL ORDER BY id FOR NO KEY UPDATE SKIP LOCKED',
            $chunk,
        ));

        if ($locked === []) {
            return 0;
        }

        $scores = $this->scores($locked, $now, $decayDays);
        $values = [];
        $bindings = [];

        foreach ($scores as $id => $score) {
            $values[] = '(?::char(26), ?::int)';
            $bindings[] = $id;
            $bindings[] = $score;
        }

        return DB::affectingStatement(
            'UPDATE users u SET risk_score = v.s FROM (VALUES '.implode(', ', $values).') AS v(id, s) WHERE u.id = v.id AND u.risk_score <> v.s',
            $bindings,
        );
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, int> user id => score (0 for a member without signals)
     */
    private function scores(array $userIds, CarbonImmutable $now, int $decayDays): array
    {
        $bySubject = array_fill_keys($userIds, []);

        foreach (Sql::select(
            'SELECT user_id, kind, weight, detected_at, outcome FROM risk_signals WHERE user_id IN ('.Sql::placeholders($userIds).') AND weight > 0',
            $userIds,
        ) as $row) {
            $bySubject[$row->user_id][] = new ScoredSignal(
                RiskSignalKind::from($row->kind),
                (int) $row->weight,
                CarbonImmutable::parse($row->detected_at, 'UTC'),
                $row->outcome === null ? null : RiskOutcome::from($row->outcome),
            );
        }

        return array_map(fn (array $signals) => $this->calculator->score($signals, $now, $decayDays), $bySubject);
    }
}
