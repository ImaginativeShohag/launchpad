<?php

use App\Actions\Notification\RecordNotification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/MailFixtures.php';
require_once __DIR__.'/../../Support/NotificationFixtures.php';

it('queues one mail for a verified member, with the current address and the given payload', function () {
    // Given a verified member and a draft with an email key
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $this->travelTo('2026-10-21 10:00:00');

    // When it is recorded
    $inserted = app(RecordNotification::class)->handle(outboxDraft($user));

    // Then the bell row and one queued outbox row exist, due now
    expect($inserted)->toBeTrue();
    $mail = DB::table('email_messages')->first();
    expect(DB::table('email_messages')->count())->toBe(1)
        ->and($mail->user_id)->toBe($user->id)->and($mail->type)->toBe('review_outcome')->and($mail->status)->toBe('queued')
        ->and($mail->to_email)->toBe('ada@example.com')->and((int) $mail->attempts)->toBe(0)
        ->and($mail->idempotency_key)->toBe('review_outcome:r1:'.$user->id)
        ->and(json_decode($mail->payload, true))->toEqual(['review_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'decision' => 'approved'])
        ->and(strtotime($mail->next_attempt_at))->toBe(strtotime('2026-10-21 10:00:00 UTC'))
        ->and($mail->last_error)->toBeNull()->and($mail->sent_at)->toBeNull()->and($mail->provider_message_id)->toBeNull();
    expect(strlen($mail->id))->toBe(26);
    expect(DB::table('notifications')->count())->toBe(1);
});

it('gives an unverified member the bell and no mail', function () {
    // Given an unverified member
    $user = User::factory()->unverified()->create();

    // When a draft with an email key is recorded
    app(RecordNotification::class)->handle(outboxDraft($user));

    // Then only the bell row exists
    expect(DB::table('notifications')->count())->toBe(1)->and(DB::table('email_messages')->count())->toBe(0);
});

it('skips the mail when the member switched that type off, and sends it when the preference is on or missing', function (?bool $enabled, int $mails) {
    // Given a member with (or without) a preference for review_outcome
    $user = User::factory()->create();
    if ($enabled !== null) {
        DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => 'review_outcome', 'enabled' => $enabled, 'updated_at' => now()]);
    }

    // When a review notification is recorded
    app(RecordNotification::class)->handle(outboxDraft($user));

    // Then the bell always gets it, the mail depends on the preference
    expect(DB::table('notifications')->count())->toBe(1)->and(DB::table('email_messages')->count())->toBe($mails);
})->with([[false, 0], [true, 1], [null, 1]]);

it('keeps the mail of another type when only one type is off', function () {
    // Given a member who switched weekly_best off
    $user = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => 'weekly_best', 'enabled' => false, 'updated_at' => now()]);

    // When a review notification is recorded
    app(RecordNotification::class)->handle(outboxDraft($user));

    // Then the review mail is queued
    expect(DB::table('email_messages')->count())->toBe(1);
});

it('queues the same mail key once', function () {
    // Given a member and a recorded draft
    $user = User::factory()->create();
    app(RecordNotification::class)->handle(outboxDraft($user));

    // When the same draft and a draft with another bell key but the same mail key are recorded
    $again = app(RecordNotification::class)->handle(outboxDraft($user));
    app(RecordNotification::class)->handle(outboxDraft($user, 'review:other'));

    // Then there is one outbox row (and two bell rows)
    expect($again)->toBeFalse()->and(DB::table('email_messages')->count())->toBe(1)->and(DB::table('notifications')->count())->toBe(2);
});

it('repairs a lost outbox row for an existing bell row without a second broadcast', function () {
    // Given a recorded draft whose outbox row was lost
    $user = User::factory()->create();
    app(RecordNotification::class)->handle(outboxDraft($user));
    DB::table('email_messages')->delete();
    notifClearFrames();

    // When the same draft is recorded again (the reconcile sweep does this)
    $inserted = app(RecordNotification::class)->handle(outboxDraft($user));

    // Then the outbox row is back, nothing new was inserted in-site and nothing was broadcast
    expect($inserted)->toBeFalse()->and(DB::table('email_messages')->count())->toBe(1)->and(DB::table('notifications')->count())->toBe(1);
    expect(notifFramesOn('private-user.'.$user->id))->toBe([]);
});

it('commits the bell row and the mail row together or not at all', function () {
    // Given a member and a draft whose mail key breaks the column (over 191 characters)
    $user = User::factory()->create(['email' => 'secret.person@example.com']);
    $draft = outboxDraft($user, 'review:big', str_repeat('k', 200));

    // When it is recorded
    // Then the second insert fails with a bindings-free RuntimeException that names the SQLSTATE only, and neither row exists
    try {
        app(RecordNotification::class)->handle($draft);
        $thrown = null;
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(RuntimeException::class)->not->toBeInstanceOf(QueryException::class);
    expect($thrown->getMessage())->toBe('Writing email_messages failed (SQLSTATE 22001).')->not->toContain('secret.person')->and($thrown->getPrevious())->toBeNull();
    expect(DB::table('notifications')->count())->toBe(0)->and(DB::table('email_messages')->count())->toBe(0);
});

it('writes no mail without an email key', function () {
    // Given a member and a draft without a mail key
    $user = User::factory()->create();

    // When it is recorded
    app(RecordNotification::class)->handle(outboxDraft($user, 'review:r1', null));

    // Then there is a bell row only
    expect(DB::table('notifications')->count())->toBe(1)->and(DB::table('email_messages')->count())->toBe(0);
});

it('writes nothing for a deleted or suspended recipient, mail included', function (array $state) {
    // Given a recipient who cannot be notified
    $user = User::factory()->create($state);

    // When a draft is recorded
    $inserted = app(RecordNotification::class)->handle(outboxDraft($user));

    // Then neither table has a row
    expect($inserted)->toBeFalse()->and(DB::table('notifications')->count())->toBe(0)->and(DB::table('email_messages')->count())->toBe(0);
})->with([[['deleted_at' => '2026-10-20 10:00:00']], [['suspended_at' => '2026-10-20 10:00:00']]]);

it('refuses an outbox payload that holds more than ids and small scalars', function (array $payload) {
    // Given a member
    $user = User::factory()->create();

    // When a draft with a hostile payload is recorded
    // Then it is refused before any write
    expect(fn () => app(RecordNotification::class)->handle(outboxDraft($user, 'review:r1', 'k:1', $payload)))->toThrow(InvalidArgumentException::class);
    expect(DB::table('notifications')->count())->toBe(0);
})->with([
    'nested object' => [['a' => ['b' => 'c']]],
    'float' => [['a' => 1.5]],
    'integer key' => [[0 => 'x']],
    'long string' => [['a' => str_repeat('x', 192)]],
    'object list' => [['a' => [['b' => 1]]]],
]);

it('reads the recipient FOR SHARE once for bell and mail', function () {
    // Given a member
    $user = User::factory()->create();
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    // When a draft is recorded
    app(RecordNotification::class)->handle(outboxDraft($user));

    // Then the user row was read with a share lock and the mail insert has an ON CONFLICT on the key
    expect(collect($sql)->filter(fn (string $s) => str_contains($s, 'from "users"') && str_contains($s, 'for share'))->count())->toBe(1);
    expect(collect($sql)->contains(fn (string $s) => str_contains($s, 'insert into email_messages') && str_contains($s, 'on conflict (idempotency_key) do nothing')))->toBeTrue();
});
