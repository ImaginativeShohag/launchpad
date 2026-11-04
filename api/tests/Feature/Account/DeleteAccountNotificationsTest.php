<?php

use App\Actions\Auth\DeleteAccount;
use App\Actions\Notification\RecordNotification;
use App\Enums\EmailType;
use App\Enums\Role;
use App\Models\EmailMessage;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/MailFixtures.php';
require_once __DIR__.'/../../Support/NotificationFixtures.php';

afterEach(fn () => User::flushEventListeners());

/** The anonymised address DeleteAccount writes for $user. */
function deleteAccountAnonymised(User $user): string
{
    return 'deleted+'.Str::lower($user->id).'@invalid';
}

/**
 * A member with the full set of notification data: read and unread bell rows, preferences, and one outbox row in each state.
 *
 * @return array{user: User, rows: array<string, EmailMessage>}
 */
function deleteAccountMember(?User $user = null): array
{
    $user ??= User::factory()->create(['email' => 'ada-'.Str::random(6).'@example.com']);
    notifBulk($user->id, 3);
    notifBulk($user->id, 2, '2026-10-20 11:00:00+00', 10);
    foreach (['daily_top5', 'weekly_best'] as $type) {
        DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => $type, 'enabled' => false, 'updated_at' => now()]);
    }
    $payload = ['product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'launch_date' => '2026-10-22'];
    $rows = [
        'queued' => EmailMessage::factory()->forUser($user)->ofType(EmailType::ProductLive, $payload)->create(),
        'sending' => EmailMessage::factory()->forUser($user)->ofType(EmailType::ProductLive, $payload)->sending(now()->addMinutes(10), 1)->create(),
        'sent' => EmailMessage::factory()->forUser($user)->ofType(EmailType::ProductLive, $payload)->sent()->create(),
        'failed' => EmailMessage::factory()->forUser($user)->ofType(EmailType::ProductLive, $payload)->failed()->create(['last_error' => 'RuntimeException: rejected ada@example.com']),
        'suppressed' => EmailMessage::factory()->forUser($user)->ofType(EmailType::ProductLive, $payload)->suppressed('opted_out')->create(),
    ];

    return ['user' => $user, 'rows' => $rows];
}

it('deletes the bell rows and preferences and scrubs the outbox rows of the deleted member only', function () {
    // Given a member with notification data, and another member with the same kinds of rows
    ['user' => $user, 'rows' => $rows] = deleteAccountMember();
    ['user' => $other, 'rows' => $otherRows] = deleteAccountMember();
    $otherBefore = DB::table('email_messages')->where('user_id', $other->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

    // When the first member deletes their account
    expect(app(DeleteAccount::class)->handle($user))->toBeTrue();

    // Then their bell rows and preferences are gone
    expect(DB::table('notifications')->where('user_id', $user->id)->count())->toBe(0)->and(DB::table('email_preferences')->where('user_id', $user->id)->count())->toBe(0);

    // And the two waiting rows are suppressed with an empty payload and no due time
    foreach (['queued', 'sending'] as $state) {
        $row = DB::table('email_messages')->where('id', $rows[$state]->id)->first();
        expect($row->status)->toBe('suppressed')->and($row->next_attempt_at)->toBeNull()->and(json_decode($row->payload, true))->toBe([])->and($row->last_error)->toBe('user_deleted');
    }

    // And every row, finished or not, carries the anonymised address and no error text with an address
    $all = DB::table('email_messages')->where('user_id', $user->id)->get();
    expect($all)->toHaveCount(5);
    foreach ($all as $row) {
        expect($row->to_email)->toBe(deleteAccountAnonymised($user))->and((string) $row->last_error)->not->toContain('@example.com');
    }
    expect(DB::table('email_messages')->where('id', $rows['sent']->id)->value('status'))->toBe('sent')
        ->and(DB::table('email_messages')->where('id', $rows['failed']->id)->value('last_error'))->toBeNull()
        ->and(DB::table('email_messages')->where('id', $rows['suppressed']->id)->value('last_error'))->toBeNull();

    // And the other member's rows are untouched
    expect(DB::table('notifications')->where('user_id', $other->id)->count())->toBe(5)->and(DB::table('email_preferences')->where('user_id', $other->id)->count())->toBe(2);
    expect(DB::table('email_messages')->where('user_id', $other->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all())->toBe($otherBefore);
});

it('changes nothing when the deletion is refused', function (string $reason) {
    // Given a member who cannot delete (a live product, or the last admin) with notification data
    $user = User::factory()->create(['role' => $reason === 'last_admin' ? Role::Admin : Role::Member]);
    deleteAccountMember($user);
    if ($reason === 'has_active_product') {
        Product::factory()->live()->create(['maker_id' => $user->id]);
    }
    $snapshot = fn () => [
        DB::table('notifications')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('email_preferences')->where('user_id', $user->id)->orderBy('type')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('email_messages')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
    $before = $snapshot();

    // When they try to delete their account
    expect(fn () => app(DeleteAccount::class)->handle($user))->toThrow(Exception::class);

    // Then every row is exactly as it was
    expect($snapshot())->toBe($before);
})->with(['has_active_product', 'last_admin']);

it('changes nothing on the duplicate-request path', function () {
    // Given a member deleted once, then new notification data written for the account (as a racing writer could not, but a stale call might see)
    ['user' => $user] = deleteAccountMember();
    $stale = User::query()->findOrFail($user->id);
    app(DeleteAccount::class)->handle($user);
    $before = [DB::table('notifications')->count(), DB::table('email_messages')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];

    // When the stale second request runs
    $second = app(DeleteAccount::class)->handle($stale);

    // Then it reports "not deleted by me" and nothing changed
    expect($second)->toBeFalse()->and([DB::table('notifications')->count(), DB::table('email_messages')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->toBe($before);
});

it('sends nothing afterwards: a row queued just before the deletion is suppressed by the next run', function () {
    // Given a member with a due, sendable row, and the mail array transport empty
    $user = User::factory()->create();
    $row = outboxReady($user);
    outboxForgetSent();

    // When the account is deleted and the dispatcher runs
    app(DeleteAccount::class)->handle($user);
    $this->artisan('launchpad:send-emails')->assertExitCode(0);

    // Then no mail went out and the row is suppressed
    expect(outboxSent())->toHaveCount(0)->and(DB::table('email_messages')->where('id', $row->id)->value('status'))->toBe('suppressed');
});

it('writes nothing for a deleted member: no bell row, no outbox row, no broadcast', function () {
    // Given a deleted member
    $user = User::factory()->create();
    app(DeleteAccount::class)->handle($user);
    notifClearFrames();

    // When a notification is recorded for them
    $recorded = app(RecordNotification::class)->handle(outboxDraft($user));

    // Then nothing was written or broadcast
    expect($recorded)->toBeFalse()->and(DB::table('notifications')->count())->toBe(0)->and(DB::table('email_messages')->count())->toBe(0);
    expect(notifFramesOn('private-user.'.$user->id))->toBe([]);
});

it('takes the locks in the global order: products, then the user row, then the notification statements, and no outbox row lock', function () {
    // Given a member with a draft product and notification data, and a recorder of every statement
    ['user' => $user] = deleteAccountMember();
    Product::factory()->complete()->create(['maker_id' => $user->id]);
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    // When the member is deleted
    app(DeleteAccount::class)->handle($user);

    // Then the product lock comes first, the user lock second, and the notification statements after them
    $position = function (callable $match) use ($sql): ?int {
        foreach ($sql as $i => $statement) {
            if ($match($statement)) {
                return $i;
            }
        }

        return null;
    };
    $products = $position(fn (string $s) => str_contains($s, 'from "products"') && str_contains($s, 'for update'));
    $userLock = $position(fn (string $s) => str_contains($s, 'from "users"') && str_contains($s, 'for update') && ! str_contains($s, '"role"'));
    $bell = $position(fn (string $s) => str_starts_with($s, 'delete from "notifications"'));
    $prefs = $position(fn (string $s) => str_starts_with($s, 'delete from "email_preferences"'));
    $mail = $position(fn (string $s) => str_starts_with($s, 'update "email_messages"') || str_starts_with($s, 'update email_messages'));
    $anonymise = $position(fn (string $s) => str_starts_with($s, 'update "users"'));
    expect($products)->not->toBeNull()->and($userLock)->toBeGreaterThan($products);
    expect($bell)->toBeGreaterThan($userLock)->and($prefs)->toBeGreaterThan($userLock)->and($mail)->toBeGreaterThan($userLock);
    expect($anonymise)->toBeGreaterThan($mail);
    foreach ($sql as $statement) {
        expect($statement)->not->toMatch('/email_messages.*for update/');
    }
});

it('restores the bell rows, preferences and outbox rows when the anonymising update fails', function () {
    // Given a member with notification data and an update of the user row that fails
    ['user' => $user] = deleteAccountMember();
    $snapshot = fn () => [
        DB::table('notifications')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('email_preferences')->where('user_id', $user->id)->orderBy('type')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('email_messages')->where('user_id', $user->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
    $before = $snapshot();
    User::updating(fn () => throw new RuntimeException('db down'));

    // When the member deletes their account
    // Then it fails and every row is restored with the same ids
    expect(fn () => app(DeleteAccount::class)->handle($user))->toThrow(RuntimeException::class, 'db down');
    expect($snapshot())->toBe($before)->and(DB::table('users')->where('id', $user->id)->value('deleted_at'))->toBeNull();
});
