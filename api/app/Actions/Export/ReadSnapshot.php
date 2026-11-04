<?php

namespace App\Actions\Export;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a read so that every query in it sees one database snapshot (REPEATABLE READ, READ ONLY): the four CSV files of an
 * export then agree with each other whatever the votes and the flush write meanwhile. Postgres only accepts the isolation
 * statement as the first one of a transaction, so it is applied only when no transaction is open (the queue worker). Under a
 * caller's transaction (the test suite's outer one) the read runs plain.
 */
final class ReadSnapshot
{
    public static function run(Closure $read): mixed
    {
        if (DB::transactionLevel() !== 0) {
            return $read();
        }

        return DB::transaction(function () use ($read): mixed {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');

            return $read();
        });
    }
}
