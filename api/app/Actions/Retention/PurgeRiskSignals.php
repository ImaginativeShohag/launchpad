<?php

namespace App\Actions\Retention;

use App\Enums\SettingKey;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deletes risk signals past their window: an unreviewed signal once `detected_at` is older than
 * `retention.risk_signals_days` (365), a reviewed one once `reviewed_at` is older than `retention.risk_signals_reviewed_days`
 * (90) or its `detected_at` is older than the 365 day cap. Strictly older: a row exactly at the cutoff is kept.
 *
 * Reviewed rows (confirmed or dismissed) of the kinds that dedupe once per account (`fresh_account`, `disposable_email`,
 * `single_maker_voter`) are kept for ever: their key would otherwise be free again and the next run would re-detect the same
 * thing and raise a fresh open signal for something staff already decided (see RiskSignalKey). They are one small row per
 * account. Unreviewed rows of those kinds still go at the unreviewed window.
 *
 * The `picked` step repeats the three predicates: a row reviewed after the `due` snapshot (a review commits between the two
 * steps) must not be deleted on the strength of its old state.
 *
 * The candidates come from three single-predicate branches, each served by one partial index (`risk_signals_unreviewed_detected_index`,
 * `risk_signals_reviewed_index`, `risk_signals_detected_index`), so a chunk never scans past rows that are not due. The rows are then
 * locked in a MATERIALIZED step with `FOR UPDATE SKIP LOCKED` (a row a review holds is picked up next run). No user or product
 * row is locked: the foreign keys point from this table, so deleting a row needs nothing from them. The `audit_logs` rows of
 * earlier reviews stay for ever; a purged confirmed signal simply stops counting at the next scoring pass.
 */
class PurgeRiskSignals extends RetentionPurge
{
    public function __construct(private readonly Settings $settings, int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS)
    {
        parent::__construct($chunk, $maxChunks);
    }

    public function table(): string
    {
        return 'risk_signals';
    }

    protected function purgeChunk(CarbonImmutable $now, int $limit): int
    {
        // Both windows are re-validated at read time (a stored 0 would purge everything) and read once per chunk.
        $unreviewed = $now->subDays($this->settings->days(SettingKey::RetentionRiskSignalsDays))->format('Y-m-d H:i:s.uP');
        $reviewed = $now->subDays($this->settings->days(SettingKey::RetentionRiskSignalsReviewedDays))->format('Y-m-d H:i:s.uP');
        // Reviewed rows of the once-per-account kinds are kept whatever the outcome (see the class comment).
        $keep = "kind NOT IN ('fresh_account', 'disposable_email', 'single_maker_voter')";

        return DB::delete(
            "WITH due AS MATERIALIZED (
                (SELECT id, detected_at FROM risk_signals WHERE reviewed_at IS NULL AND detected_at < ? ORDER BY detected_at LIMIT ?)
                UNION
                (SELECT id, detected_at FROM risk_signals WHERE reviewed_at IS NOT NULL AND reviewed_at < ? AND {$keep} ORDER BY reviewed_at LIMIT ?)
                UNION
                (SELECT id, detected_at FROM risk_signals WHERE reviewed_at IS NOT NULL AND detected_at < ? AND {$keep} ORDER BY detected_at LIMIT ?)
            ), picked AS MATERIALIZED (
                SELECT s.id FROM risk_signals s JOIN due ON due.id = s.id
                WHERE (s.reviewed_at IS NULL AND s.detected_at < ?)
                   OR (s.reviewed_at IS NOT NULL AND s.{$keep} AND (s.reviewed_at < ? OR s.detected_at < ?))
                ORDER BY due.detected_at, s.id LIMIT ? FOR UPDATE OF s SKIP LOCKED
            )
            DELETE FROM risk_signals USING picked WHERE risk_signals.id = picked.id",
            [$unreviewed, $limit, $reviewed, $limit, $unreviewed, $limit, $unreviewed, $reviewed, $unreviewed, $limit],
        );
    }
}
