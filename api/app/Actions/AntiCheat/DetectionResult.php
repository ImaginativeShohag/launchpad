<?php

namespace App\Actions\AntiCheat;

/** What one detection run did. Counters are merged only after the chunk that produced them committed. */
final class DetectionResult
{
    /** @var array<string, int> kind value => new rows */
    public array $signals = [];

    /** Chunks (and run rules) that threw; each was reported and the run went on. */
    public int $failedChunks = 0;

    /** Members whose stored risk score changed in the scoring pass. */
    public int $usersRescored = 0;

    /** Votes the flagging pass marked `flagged` / took the mark off again (they count either way). */
    public int $flagged = 0;

    public int $unflagged = 0;

    /** @var array<string, true> user id => true */
    private array $touched = [];

    /** @param  array<string, int>  $counts */
    public function addSignals(array $counts): void
    {
        foreach ($counts as $kind => $count) {
            $this->signals[$kind] = ($this->signals[$kind] ?? 0) + $count;
        }
    }

    /** @param  list<string>  $userIds */
    public function touch(array $userIds): void
    {
        foreach ($userIds as $id) {
            $this->touched[$id] = true;
        }
    }

    /**
     * Every member named by any finding of the run, written or deduplicated (Phase B rescoring starts from these).
     *
     * @return list<string> sorted
     */
    public function touchedUserIds(): array
    {
        $ids = array_keys($this->touched);
        sort($ids);

        return $ids;
    }

    public function totalSignals(): int
    {
        return array_sum($this->signals);
    }
}
