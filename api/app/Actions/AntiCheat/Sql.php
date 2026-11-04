<?php

namespace App\Actions\AntiCheat;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Two tiny SQL helpers the detection rules share. */
final class Sql
{
    /**
     * A raw select, typed so the rows' columns can be read as properties.
     *
     * @param  array<int, mixed>  $bindings
     * @return list<stdClass>
     */
    public static function select(string $sql, array $bindings = []): array
    {
        /** @var list<stdClass> $rows */
        $rows = DB::select($sql, $bindings);

        return $rows;
    }

    /**
     * `?, ?, ?` for a list of bindings.
     *
     * @param  array<int, mixed>  $values
     */
    public static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }

    /** An instant as a UTC literal Postgres reads back exactly (whole seconds are all the rules ever need). */
    public static function ts(CarbonInterface $at): string
    {
        return $at->copy()->utc()->format('Y-m-d H:i:s').'+00';
    }

    /** A vote that counts: not taken back and not voided (flagged still counts). The alias is the votes table's. */
    public static function activeVote(string $alias = 'v'): string
    {
        return "{$alias}.removed_at IS NULL AND {$alias}.status IN ('counted', 'flagged')";
    }

    /**
     * What staff may see of a stored hash column, computed in SQL so the full value never reaches PHP: its first eight
     * characters when it is a well-formed 64-hex string, else null (a purged or malformed value has no fingerprint).
     */
    public static function fingerprint(string $column): string
    {
        return "(CASE WHEN {$column} ~ '^[0-9a-f]{64}\$' THEN left({$column}, 8) END)";
    }
}
