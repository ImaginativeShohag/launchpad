<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** A raw follow row; `created_at` defaults to the column default. */
function followRow(User|string $follower, User|string $followed, ?string $createdAt = null): void
{
    DB::table('user_follows')->insert(array_filter([
        'follower_id' => $follower instanceof User ? $follower->id : $follower,
        'followed_id' => $followed instanceof User ? $followed->id : $followed,
        'created_at' => $createdAt,
    ]));
}

function followCount(User $follower, User $followed): int
{
    return DB::table('user_follows')->where('follower_id', $follower->id)->where('followed_id', $followed->id)->count();
}

/** The locking statements of a callback, in order, lower-cased. */
function followRecordLocks(Closure $callback): array
{
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') || str_contains($sql, 'for share') || str_contains($sql, 'for key share') || str_contains($sql, 'from "products"')) {
            $log[] = $sql;
        }
    });
    $callback();

    return $log;
}
