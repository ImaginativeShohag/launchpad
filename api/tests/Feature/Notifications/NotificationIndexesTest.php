<?php

use App\Actions\Notification\ListNotifications;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/NotificationFixtures.php';

/** Runs the real list query (first page and a cursor page) and returns the plans' JSON. */
function notifListPlans(User $user, bool $unread): array
{
    $sqls = [];
    DB::listen(function ($query) use (&$sqls) {
        $sqls[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });
    $page = app(ListNotifications::class)->handle($user->id, $unread, 20);
    app(ListNotifications::class)->handle($user->id, $unread, 20);
    $cursor = $page->nextCursor();
    request()->query->set('cursor', $cursor?->encode());
    app(ListNotifications::class)->handle($user->id, $unread, 20);
    $captured = array_values(array_filter($sqls, fn ($q) => str_contains($q['sql'], 'from "notifications"')));
    DB::statement('SET enable_seqscan = off');
    DB::statement('SET enable_bitmapscan = off');
    DB::statement('SET enable_sort = off');

    return array_map(fn ($q) => json_encode(DB::select('EXPLAIN (FORMAT JSON) '.$q['sql'], $q['bindings'])), $captured);
}

it('serves the real list queries from the matching index without a sort', function (bool $unread, string $index) {
    // Given an analysed table with thousands of rows over many members, a third of them read
    $members = User::factory()->count(25)->create();
    foreach ($members as $i => $member) {
        notifBulk($member->id, 120, null, $i * 1000);
        notifBulk($member->id, 80, 'read', 300000 + $i * 1000);
    }
    DB::statement('ANALYZE notifications');

    // When the application's real query (first page and a cursor page) is explained
    $plans = notifListPlans($members[0], $unread);

    // Then it uses the index named for it and has no Sort node (three statements: page one twice and a real cursor page)
    expect($plans)->toHaveCount(3);
    foreach ($plans as $plan) {
        expect($plan)->toContain($index)->not->toContain('"Sort"');
    }
})->with([[false, 'notifications_user_index'], [true, 'notifications_unread_index']]);
