<?php

use App\Actions\Notification\CountUnread;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/NotificationFixtures.php';

it('counts unread rows up to the cap', function (int $rows, int $count, bool $capped) {
    // Given a member with $rows unread notifications
    $user = User::factory()->create();
    notifBulk($user->id, $rows);

    // When the unread count is read
    $result = app(CountUnread::class)->handle($user->id);

    // Then it is the count, capped at 100
    expect($result)->toBe(['count' => $count, 'capped' => $capped]);
})->with([[0, 0, false], [5, 5, false], [99, 99, false], [100, 100, true], [101, 100, true], [150, 100, true]]);

it('ignores read rows and other members', function () {
    // Given three unread rows, four read rows, and another member's unread rows
    $user = User::factory()->create();
    $other = User::factory()->create();
    notifBulk($user->id, 3);
    notifBulk($user->id, 4, 'read', 10);
    notifBulk($other->id, 7);

    // When the count is read
    $result = app(CountUnread::class)->handle($user->id);

    // Then only the three unread rows of the member count
    expect($result)->toBe(['count' => 3, 'capped' => false]);
});

it('finds the newest unread id and null when nothing is unread', function () {
    // Given three unread rows and a newer read one
    $user = User::factory()->create();
    $empty = User::factory()->create();
    $old = Notification::factory()->forUser($user)->createdAt(now()->subHours(3))->create();
    $newest = Notification::factory()->forUser($user)->createdAt(now()->subHour())->create();
    Notification::factory()->forUser($user)->createdAt(now())->read()->create();

    // When the newest unread id is read
    // Then it is the newest unread row, and null for a member without unread rows
    expect(app(CountUnread::class)->newestUnreadId($user->id))->toBe($newest->id)->not->toBe($old->id);
    expect(app(CountUnread::class)->newestUnreadId($empty->id))->toBeNull();
});

it('serves the real count and newest-id SQL from the unread index without a sort', function () {
    // Given an analysed table with thousands of rows over many members
    $members = User::factory()->count(30)->create();
    foreach ($members as $i => $member) {
        notifBulk($member->id, 150, null, $i * 1000);
        notifBulk($member->id, 100, 'read', 200000 + $i * 1000);
    }
    $user = $members[0];
    DB::statement('ANALYZE notifications');

    // When the real SQL of both reads is captured
    $sqls = [];
    DB::listen(function ($query) use (&$sqls) {
        $sqls[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });
    app(CountUnread::class)->handle($user->id);
    app(CountUnread::class)->newestUnreadId($user->id);

    // Then each plan (planner flags off) uses notifications_unread_index and has no Sort node
    $captured = array_values(array_filter($sqls, fn ($q) => str_contains($q['sql'], 'FROM notifications')));
    expect($captured)->toHaveCount(2);
    DB::statement('SET enable_seqscan = off');
    DB::statement('SET enable_bitmapscan = off');
    DB::statement('SET enable_sort = off');
    foreach ($captured as $query) {
        $plan = json_encode(DB::select('EXPLAIN (FORMAT JSON) '.$query['sql'], $query['bindings']));
        expect($plan)->toContain('notifications_unread_index')->not->toContain('"Sort"');
    }
});
