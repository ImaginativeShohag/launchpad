<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * The smallest ULID of a millisecond: its 10 time characters followed by sixteen zeros. A primary-key range
 * (`id >= floor`) then selects rows created since that instant without an index on `created_at` (ULIDs sort by time).
 */
final class UlidFloor
{
    /** Uppercase Crockford, as `Str::ulid()` prints it. Stored ids are lowercase: lower-case the result for a query. */
    public static function at(CarbonInterface $time): string
    {
        return substr((string) Str::ulid($time), 0, 10).str_repeat('0', 16);
    }
}
