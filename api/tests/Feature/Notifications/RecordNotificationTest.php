<?php

use App\Actions\Notification\NotificationDraft;
use App\Actions\Notification\RecordNotification;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

require_once __DIR__.'/../../Support/NotificationFixtures.php';

it('inserts one unread row with the draft fields and microsecond timestamps', function () {
    // Given a member, a draft and a clock with microseconds
    $this->travelTo(new DateTimeImmutable('2026-10-21 10:00:00.123456 UTC'));
    $user = User::factory()->create();
    $draft = notifDraft($user, 'review:abc');

    // When it is recorded
    $recorded = app(RecordNotification::class)->handle($draft);

    // Then one new row exists with the draft's fields, unread, created_at equal to updated_at
    expect($recorded)->toBeTrue();
    $row = Notification::query()->sole();
    expect($row->user_id)->toBe($user->id)
        ->and($row->type)->toBe(NotificationType::ReviewApproved)
        ->and($row->dedupe_key)->toBe('review:abc')
        ->and($row->read_at)->toBeNull()
        ->and($row->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-21 10:00:00.123456')
        ->and($row->updated_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-21 10:00:00.123456');
    expect($row->data)->toEqual($draft->data);
});

it('writes the same key once and returns false the second time without broadcasting', function () {
    // Given a recorded draft
    $user = User::factory()->create();
    app(RecordNotification::class)->handle(notifDraft($user, 'review:abc'));
    notifClearFrames();

    // When the same (member, key) is recorded again
    $second = app(RecordNotification::class)->handle(notifDraft($user, 'review:abc'));

    // Then nothing new is written and nothing is broadcast
    expect($second)->toBeFalse()->and(Notification::query()->count())->toBe(1);
    expect(notifFrames())->toBe([]);
});

it('lets two members hold the same key', function () {
    // Given two members
    $a = User::factory()->create();
    $b = User::factory()->create();

    // When both get the same dedupe key
    $first = app(RecordNotification::class)->handle(notifDraft($a, 'review:abc'));
    $second = app(RecordNotification::class)->handle(notifDraft($b, 'review:abc'));

    // Then each has a row
    expect($first)->toBeTrue()->and($second)->toBeTrue()->and(Notification::query()->count())->toBe(2);
});

it('writes nothing for a deleted, suspended or unknown recipient', function (string $case) {
    // Given a recipient who cannot receive
    $id = match ($case) {
        'deleted' => User::factory()->create(['deleted_at' => now()])->id,
        'suspended' => User::factory()->suspended()->create()->id,
        'unknown' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
    };
    notifClearFrames();

    // When a notification is recorded for them
    $recorded = app(RecordNotification::class)->handle(notifDraft($id));

    // Then nothing is written or broadcast
    expect($recorded)->toBeFalse()->and(Notification::query()->count())->toBe(0);
    expect(notifFrames())->toBe([]);
})->with(['deleted', 'suspended', 'unknown']);

it('gives an unverified member the bell row', function () {
    // Given a member who has not verified their email
    $user = User::factory()->unverified()->create();

    // When a notification is recorded
    $recorded = app(RecordNotification::class)->handle(notifDraft($user));

    // Then the row exists (preferences and verification only gate email)
    expect($recorded)->toBeTrue()->and(Notification::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('round-trips nested data', function () {
    // Given a draft with nested params and unicode
    $user = User::factory()->create();
    $draft = notifDraft($user, 'comment:1', NotificationType::NewComment, ['params' => ['product_name' => 'Café ☕', 'kind' => 'reply']]);

    // When recorded and read back
    app(RecordNotification::class)->handle($draft);

    // Then data is equal (jsonb reorders keys, so toEqual and not toBe)
    expect(Notification::query()->sole()->data)->toEqual($draft->data);
});

it('does not poison an outer transaction when the insert fails', function () {
    // Given a draft the database refuses (an unknown-length key is truncated by varchar: use an over-long dedupe key)
    $user = User::factory()->create();
    $tooLong = notifDraft($user, str_repeat('k', 192));

    // When it runs inside an outer transaction and the failure is caught
    $failed = false;
    DB::transaction(function () use ($tooLong, &$failed) {
        try {
            app(RecordNotification::class)->handle($tooLong);
        } catch (QueryException) {
            $failed = true;
        }
        // Then the outer transaction still works (a failed statement without a savepoint would answer 25P02)
        DB::table('users')->count();
    });
    expect($failed)->toBeTrue();
});

it('still returns true, keeps the row and reports when the broadcaster throws', function () {
    // Given a broadcaster that fails and a reporter that records
    notifBreakBroadcaster();
    Exceptions::fake();
    $user = User::factory()->create();

    // When a notification is recorded
    $recorded = app(RecordNotification::class)->handle(notifDraft($user));

    // Then it succeeded, the row is committed and the failure was reported
    expect($recorded)->toBeTrue()->and(Notification::query()->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Reverb is down');
});

it('broadcasts exactly one notification.count frame to the private user channel', function () {
    // Given a member with one older unread row
    $user = User::factory()->create();
    Notification::factory()->forUser($user)->createdAt(now()->subMinute())->create();
    notifClearFrames();

    // When a second notification is recorded
    app(RecordNotification::class)->handle(notifDraft($user, 'review:new'));

    // Then one frame went to private-user.{id} carrying the count (2) and the newest unread id, and nothing else
    $frames = notifFramesOn('private-user.'.$user->id);
    expect($frames)->toHaveCount(1)->and(notifFrames())->toHaveCount(1);
    expect($frames[0]['name'])->toBe('notification.count');
    expect($frames[0]['data'])->toBe(['unread_count' => 2, 'notification_id' => Notification::query()->where('dedupe_key', 'review:new')->value('id')]);
});

it('reads the recipient FOR SHARE before inserting and takes no other row lock', function () {
    // Given a member and a query recorder
    $user = User::factory()->create();
    $sqls = [];
    DB::listen(function ($query) use (&$sqls) {
        $sqls[] = strtolower($query->sql);
    });

    // When a notification is recorded
    app(RecordNotification::class)->handle(notifDraft($user));

    // Then the user row is read with FOR SHARE (a concurrent DeleteAccount is waited for) before the INSERT, and nothing else is locked
    $locks = array_values(array_filter($sqls, fn (string $q) => str_contains($q, ' for share') || str_contains($q, ' for update') || str_contains($q, ' for key share')));
    expect($locks)->toHaveCount(1)->and($locks[0])->toContain('from "users"')->toContain('for share');
    $insert = array_key_first(array_filter($sqls, fn (string $q) => str_starts_with($q, 'insert into notifications')));
    $share = array_key_first(array_filter($sqls, fn (string $q) => str_contains($q, ' for share')));
    expect($share)->toBeLessThan($insert);
});

it('accepts a well-shaped draft of every notification type', function (NotificationType $type) {
    // Given a member and a draft of this type with ids, scalar params and a relative href
    $user = User::factory()->create();
    $draft = notifDraft($user, 'k:'.$type->value, $type, ['comment_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAX', 'params' => ['product_name' => 'Acme', 'kind' => 'reply', 'n' => 3, 'ok' => true, 'none' => null], 'href' => '/products/acme']);

    // When recorded
    // Then it is stored (and the type CHECK accepts the value)
    expect(app(RecordNotification::class)->handle($draft))->toBeTrue();
    expect(Notification::query()->where('user_id', $user->id)->sole()->type)->toBe($type);
})->with(NotificationType::cases());

it('refuses a badly shaped draft before running any query', function (array $data, ?NotificationType $type) {
    // Given a member and a query recorder
    $user = User::factory()->create();
    $draft = new NotificationDraft($user->id, $type ?? NotificationType::ReviewApproved, 'k:1', $data);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    // When it is recorded
    // Then it throws InvalidArgumentException and nothing ran or was written
    expect(fn () => app(RecordNotification::class)->handle($draft))->toThrow(InvalidArgumentException::class);
    expect($queries)->toBe(0)->and(Notification::query()->count())->toBe(0);
})->with([
    'extra key' => [['message_key' => 'notifications.review_approved', 'note' => 'Reviewer said no'], null],
    'body key' => [['message_key' => 'notifications.review_approved', 'body' => 'hello'], null],
    'no message key' => [['href' => '/x'], null],
    'wrong message key' => [['message_key' => 'notifications.new_comment'], null],
    'message key not a string' => [['message_key' => ['x']], null],
    'absolute href' => [['message_key' => 'notifications.review_approved', 'href' => 'https://evil.example/x'], null],
    'protocol relative href' => [['message_key' => 'notifications.review_approved', 'href' => '//evil.example/x'], null],
    'backslash href' => [['message_key' => 'notifications.review_approved', 'href' => '/\\evil.example'], null],
    'empty href' => [['message_key' => 'notifications.review_approved', 'href' => ''], null],
    'array href' => [['message_key' => 'notifications.review_approved', 'href' => ['/x']], null],
    'params not array' => [['message_key' => 'notifications.review_approved', 'params' => 'x'], null],
    'nested params' => [['message_key' => 'notifications.review_approved', 'params' => ['a' => ['b']]], null],
    'object param' => [['message_key' => 'notifications.review_approved', 'params' => ['a' => new stdClass]], null],
    'list params' => [['message_key' => 'notifications.review_approved', 'params' => ['x']], null],
    'id not string' => [['message_key' => 'notifications.review_approved', 'product_id' => 5], null],
]);
