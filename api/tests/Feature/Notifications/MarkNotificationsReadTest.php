<?php

use App\Actions\Notification\MarkNotificationsRead;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

require_once __DIR__.'/../../Support/NotificationFixtures.php';

it('marks only the caller\'s unread rows by id and returns the really changed count', function () {
    // Given two unread rows, one already read row, a foreign unread row
    $user = User::factory()->create();
    $other = User::factory()->create();
    $a = Notification::factory()->forUser($user)->create();
    $b = Notification::factory()->forUser($user)->create();
    $read = Notification::factory()->forUser($user)->read()->create();
    $foreign = Notification::factory()->forUser($other)->create();
    notifClearFrames();

    // When they are marked by id together with an unknown id
    $marked = app(MarkNotificationsRead::class)->handle($user->id, [$a->id, $read->id, $foreign->id, '0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b'], false);

    // Then only the one unread own row changed
    expect($marked)->toBe(1);
    expect($a->fresh()->read_at)->not->toBeNull()->and($b->fresh()->read_at)->toBeNull()->and($foreign->fresh()->read_at)->toBeNull();
});

it('is idempotent: a repeat marks nothing and keeps read_at', function () {
    // Given a marked row
    $user = User::factory()->create();
    $row = Notification::factory()->forUser($user)->create();
    app(MarkNotificationsRead::class)->handle($user->id, [$row->id], false);
    $readAt = $row->fresh()->read_at->format('Y-m-d H:i:s.u');
    notifClearFrames();

    // When the same call repeats
    $marked = app(MarkNotificationsRead::class)->handle($user->id, [$row->id], false);

    // Then it marks 0, read_at is unchanged and nothing is broadcast
    expect($marked)->toBe(0)->and($row->fresh()->read_at->format('Y-m-d H:i:s.u'))->toBe($readAt);
    expect(notifFrames())->toBe([]);
});

it('marks every unread row of the caller with all and none of another member', function () {
    // Given unread rows for two members
    $user = User::factory()->create();
    $other = User::factory()->create();
    Notification::factory()->forUser($user)->count(3)->createdAt(now()->subMinute())->create();
    $foreign = Notification::factory()->forUser($other)->createdAt(now()->subMinute())->create();

    // When the caller marks all
    $marked = app(MarkNotificationsRead::class)->handle($user->id, null, true);

    // Then the three of the caller are read and the foreign one is not
    expect($marked)->toBe(3)->and(Notification::query()->where('user_id', $user->id)->whereNull('read_at')->count())->toBe(0);
    expect($foreign->fresh()->read_at)->toBeNull();
});

it('leaves a notification created after the cutoff unread when marking all', function () {
    // Given one old row and one whose created_at lies after the clock the call reads
    $user = User::factory()->create();
    Notification::factory()->forUser($user)->createdAt(now()->subMinute())->create();
    $later = Notification::factory()->forUser($user)->createdAt(now()->addMinutes(5))->create();

    // When the caller marks all
    $marked = app(MarkNotificationsRead::class)->handle($user->id, null, true);

    // Then only the older row was marked; the later one stays unread (and the CHECK read_at >= created_at was never at risk)
    expect($marked)->toBe(1)->and($later->fresh()->read_at)->toBeNull();
});

it('keeps read_at at or after created_at even for a row stamped by a faster clock', function () {
    // Given an unread row created in the future, marked by id
    $user = User::factory()->create();
    $future = Notification::factory()->forUser($user)->createdAt(now()->addMinutes(5))->create();

    // When it is marked
    $marked = app(MarkNotificationsRead::class)->handle($user->id, [$future->id], false);

    // Then the CHECK holds (read_at is not before created_at)
    $row = $future->fresh();
    expect($marked)->toBe(1)->and($row->read_at->gte($row->created_at))->toBeTrue();
});

it('broadcasts once when something changed and not when nothing did', function () {
    // Given two unread rows
    $user = User::factory()->create();
    $a = Notification::factory()->forUser($user)->createdAt(now()->subMinute())->create();
    Notification::factory()->forUser($user)->createdAt(now()->subMinute())->create();
    notifClearFrames();

    // When one is marked, then the same one again
    app(MarkNotificationsRead::class)->handle($user->id, [$a->id], false);
    $afterFirst = notifFramesOn('private-user.'.$user->id);
    app(MarkNotificationsRead::class)->handle($user->id, [$a->id], false);

    // Then the first call sent one frame with the remaining count and the second added none
    expect($afterFirst)->toHaveCount(1)->and($afterFirst[0]['data']['unread_count'])->toBe(1);
    expect(notifFramesOn('private-user.'.$user->id))->toHaveCount(1);
});

it('does not fail or change the count when the broadcaster throws', function () {
    // Given a failing broadcaster and one unread row
    notifBreakBroadcaster();
    Exceptions::fake();
    $user = User::factory()->create();
    $row = Notification::factory()->forUser($user)->createdAt(now()->subMinute())->create();

    // When it is marked
    $marked = app(MarkNotificationsRead::class)->handle($user->id, [$row->id], false);

    // Then the write is committed, the count is right and the failure was reported
    expect($marked)->toBe(1)->and($row->fresh()->read_at)->not->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb is down');
});

it('requires exactly one of ids and all', function (?array $ids, bool $all) {
    // Given a call that passes both or neither
    // When it runs
    // Then it is a programming error
    expect(fn () => app(MarkNotificationsRead::class)->handle('01ARZ3NDEKTSV4RRFFQ69G5FAV', $ids, $all))->toThrow(InvalidArgumentException::class);
})->with([[['0190a1b2-c3d4-7e5f-8a6b-7c8d9e0f1a2b'], true], [null, false]]);

it('does one UPDATE statement for the whole call', function () {
    // Given five unread rows
    $user = User::factory()->create();
    Notification::factory()->forUser($user)->count(5)->createdAt(now()->subMinute())->create();

    // When all are marked and the statements are counted
    $updates = 0;
    DB::listen(function ($query) use (&$updates) {
        if (str_contains(strtolower($query->sql), 'update notifications')) {
            $updates++;
        }
    });
    app(MarkNotificationsRead::class)->handle($user->id, null, true);

    // Then one UPDATE ran
    expect($updates)->toBe(1);
});

it('locks the rows in id order inside a materialized CTE before updating', function (bool $all) {
    // Given an unread row and a query recorder
    $user = User::factory()->create();
    $row = Notification::factory()->forUser($user)->createdAt(now()->subMinute())->create();
    $sqls = [];
    DB::listen(function ($query) use (&$sqls) {
        $sqls[] = strtolower($query->sql);
    });

    // When it is marked
    app(MarkNotificationsRead::class)->handle($user->id, $all ? null : [$row->id], $all);

    // Then the single statement picks the rows ORDER BY id FOR UPDATE in a MATERIALIZED CTE and updates from it
    $update = array_values(array_filter($sqls, fn (string $q) => str_contains($q, 'update notifications')));
    expect($update)->toHaveCount(1);
    expect($update[0])->toContain('with picked as materialized (select id from notifications')
        ->toContain('order by id for update)')->toContain('from picked where notifications.id = picked.id');
})->with([[false], [true]]);
