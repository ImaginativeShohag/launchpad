<?php

namespace App\Actions\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * One table's retention purge. The loop is shared; a subclass names its table and runs one bounded chunk.
 *
 * Every chunk is its own short transaction (a savepoint when a caller already holds one), so a failing statement is
 * rolled back by itself and earlier chunks stay committed. A run handles at most `maxChunks` chunks: a backlog is
 * worked off over several days instead of one run holding the database for ever. A chunk smaller than the limit means
 * "nothing left" (or the rest is locked by someone else and is picked up next run), so the loop always ends.
 */
abstract class RetentionPurge
{
    public const CHUNK = 2000;

    public const MAX_CHUNKS = 100;

    /** Set by the last run: true when it stopped at the chunk cap while rows may remain for the next run. */
    public bool $capped = false;

    /** Rows already purged by the current/last run, kept even when a later chunk throws (earlier chunks are committed). */
    public int $purgedSoFar = 0;

    protected readonly int $chunk;

    protected readonly int $maxChunks;

    public function __construct(int $chunk = self::CHUNK, int $maxChunks = self::MAX_CHUNKS)
    {
        $this->chunk = max(1, $chunk);
        $this->maxChunks = max(1, $maxChunks);
    }

    abstract public function table(): string;

    /**
     * Handles one chunk of at most $limit rows older than the table's cutoff.
     *
     * @return int rows purged by this chunk
     */
    abstract protected function purgeChunk(CarbonImmutable $now, int $limit): int;

    /**
     * @param  CarbonImmutable  $now  the run's single clock reading (UTC)
     * @return int rows purged (deleted, or for votes: emptied)
     */
    public function purge(CarbonImmutable $now): int
    {
        $this->capped = false;
        $this->purgedSoFar = 0;
        $total = 0;

        for ($chunks = 0; $chunks < $this->maxChunks; $chunks++) {
            $done = DB::transaction(fn (): int => $this->purgeChunk($now, $this->chunk));
            $total += $done;
            $this->purgedSoFar = $total;

            if ($done < $this->chunk) {
                return $total;
            }
        }

        $this->capped = true;

        return $total;
    }
}
