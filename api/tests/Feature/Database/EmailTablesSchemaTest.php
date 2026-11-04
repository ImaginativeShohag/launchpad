<?php

use App\Enums\EmailStatus;
use App\Enums\EmailType;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The SQLSTATE of the exception a statement raises inside a savepoint, or null when it succeeds. */
function emailSqlState(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

/** @return array<string, mixed> a raw email_messages row that satisfies every constraint unless overridden */
function emailRow(string $userId, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(), 'user_id' => $userId, 'type' => 'review_outcome', 'idempotency_key' => 'k:'.Str::ulid(),
        'to_email' => 'a@example.com', 'payload' => '{}', 'status' => 'queued', 'attempts' => 0,
        'next_attempt_at' => '2026-10-21 10:00:00+00', 'last_error' => null, 'provider_message_id' => null, 'sent_at' => null,
        'created_at' => '2026-10-21 10:00:00+00', 'updated_at' => '2026-10-21 10:00:00+00',
    ], $overrides);
}

it('has the documented email_preferences columns and primary key', function () {
    // Given the migrated schema
    // When the columns are read
    $columns = DB::table('information_schema.columns')->where('table_name', 'email_preferences')->get()->keyBy('column_name');

    // Then they are exactly the documented ones
    expect($columns->keys()->all())->toEqualCanonicalizing(['user_id', 'type', 'enabled', 'updated_at']);
    expect($columns['user_id']->data_type)->toBe('character')->and((int) $columns['user_id']->character_maximum_length)->toBe(26)
        ->and($columns['type']->data_type)->toBe('character varying')->and((int) $columns['type']->character_maximum_length)->toBe(32)
        ->and($columns['enabled']->data_type)->toBe('boolean')
        ->and($columns['updated_at']->data_type)->toBe('timestamp with time zone')->and((int) $columns['updated_at']->datetime_precision)->toBe(6);
    foreach ($columns as $column) {
        expect($column->is_nullable)->toBe('NO');
    }
    $primary = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'email_preferences'::regclass AND contype = 'p'");
    expect($primary->def)->toBe('PRIMARY KEY (user_id, type)');
});

it('refuses a preference for a mandatory or unknown type', function (string $type) {
    // Given a member
    $user = User::factory()->create();

    // When a preference row for that type is inserted
    $state = emailSqlState(fn () => DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => $type, 'enabled' => false, 'updated_at' => now()]));

    // Then Postgres refuses it with check_violation
    expect($state)->toBe('23514');
})->with(['verify_email', 'reset_password', 'email_change', 'bogus']);

it('accepts a preference for each of the seven optional types once and refuses a duplicate', function () {
    // Given a member
    $user = User::factory()->create();

    // When every optional type is stored, and one of them twice
    foreach (EmailType::preferenceTypes() as $type) {
        DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => $type->value, 'enabled' => true, 'updated_at' => now()]);
    }
    $duplicate = emailSqlState(fn () => DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => 'daily_top5', 'enabled' => false, 'updated_at' => now()]));

    // Then seven rows exist and the duplicate is a unique_violation
    expect(DB::table('email_preferences')->where('user_id', $user->id)->count())->toBe(7)->and($duplicate)->toBe('23505');
});

it('has the documented email_messages columns', function () {
    // Given the migrated schema
    // When the columns are read
    $columns = DB::table('information_schema.columns')->where('table_name', 'email_messages')->get()->keyBy('column_name');

    // Then they are exactly the documented ones with the documented types and nullability
    expect($columns->keys()->all())->toEqualCanonicalizing(['id', 'user_id', 'type', 'idempotency_key', 'to_email', 'payload', 'status', 'attempts', 'next_attempt_at', 'last_error', 'provider_message_id', 'sent_at', 'created_at', 'updated_at']);
    expect($columns['id']->data_type)->toBe('character')->and((int) $columns['id']->character_maximum_length)->toBe(26)
        ->and($columns['payload']->data_type)->toBe('jsonb')
        ->and($columns['attempts']->data_type)->toBe('smallint')
        ->and((int) $columns['idempotency_key']->character_maximum_length)->toBe(191)
        ->and((int) $columns['to_email']->character_maximum_length)->toBe(255)
        ->and((int) $columns['last_error']->character_maximum_length)->toBe(500)
        ->and((int) $columns['provider_message_id']->character_maximum_length)->toBe(255)
        ->and((int) $columns['status']->character_maximum_length)->toBe(16)
        ->and((int) $columns['type']->character_maximum_length)->toBe(32);
    foreach (['next_attempt_at', 'sent_at', 'created_at', 'updated_at'] as $column) {
        expect($columns[$column]->data_type)->toBe('timestamp with time zone')->and((int) $columns[$column]->datetime_precision)->toBe(6);
    }
    foreach (['next_attempt_at', 'last_error', 'provider_message_id', 'sent_at'] as $column) {
        expect($columns[$column]->is_nullable)->toBe('YES');
    }
    foreach (['id', 'user_id', 'type', 'idempotency_key', 'to_email', 'payload', 'status', 'attempts', 'created_at', 'updated_at'] as $column) {
        expect($columns[$column]->is_nullable)->toBe('NO');
    }
    expect($columns['status']->column_default)->toContain('queued')->and($columns['attempts']->column_default)->toStartWith("'0'");
});

it('rejects each state that breaks a CHECK of email_messages', function (array $overrides) {
    // Given a member
    $user = User::factory()->create();

    // When a row that breaks one CHECK is inserted
    $state = emailSqlState(fn () => DB::table('email_messages')->insert(emailRow($user->id, $overrides)));

    // Then Postgres refuses it with check_violation
    expect($state)->toBe('23514');
})->with([
    'unknown type' => [['type' => 'bogus']],
    'unknown status' => [['status' => 'paused']],
    'queued without a due time' => [['next_attempt_at' => null]],
    'sending without a due time' => [['status' => 'sending', 'next_attempt_at' => null]],
    'sent with a due time' => [['status' => 'sent', 'sent_at' => '2026-10-21 10:00:00+00']],
    'failed with a due time' => [['status' => 'failed']],
    'suppressed with a due time' => [['status' => 'suppressed']],
    'sent without sent_at' => [['status' => 'sent', 'next_attempt_at' => null]],
    'queued with sent_at' => [['sent_at' => '2026-10-21 10:00:00+00']],
    'negative attempts' => [['attempts' => -1]],
    'payload is a JSON array' => [['payload' => '[]']],
    'payload is a JSON scalar' => [['payload' => '"x"']],
]);

it('accepts every legal state of email_messages', function (array $overrides) {
    // Given a member
    $user = User::factory()->create();

    // When a legal row is inserted
    $state = emailSqlState(fn () => DB::table('email_messages')->insert(emailRow($user->id, $overrides)));

    // Then it is accepted
    expect($state)->toBeNull();
})->with([
    'queued' => [[]],
    'sending' => [['status' => 'sending', 'attempts' => 1]],
    'sent' => [['status' => 'sent', 'next_attempt_at' => null, 'sent_at' => '2026-10-21 10:00:00+00']],
    'failed' => [['status' => 'failed', 'next_attempt_at' => null]],
    'suppressed' => [['status' => 'suppressed', 'next_attempt_at' => null]],
    'a mandatory type' => [['type' => 'verify_email']],
]);

it('keeps the idempotency key unique, and a 501 character last_error fails before any CHECK', function () {
    // Given a stored message
    $user = User::factory()->create();
    DB::table('email_messages')->insert(emailRow($user->id, ['idempotency_key' => 'same']));

    // When the same key is inserted again and an over-long error is stored
    $duplicate = emailSqlState(fn () => DB::table('email_messages')->insert(emailRow($user->id, ['idempotency_key' => 'same'])));
    $tooLong = emailSqlState(fn () => DB::table('email_messages')->insert(emailRow($user->id, ['last_error' => str_repeat('x', 501)])));
    $limit = emailSqlState(fn () => DB::table('email_messages')->insert(emailRow($user->id, ['last_error' => str_repeat('x', 500)])));

    // Then the duplicate is a unique_violation, 501 characters are a string_data_right_truncation and 500 are fine
    expect($duplicate)->toBe('23505')->and($tooLong)->toBe('22001')->and($limit)->toBeNull();
});

it('blocks an unknown user, never cascades a user delete into email_messages, and cascades preferences only', function () {
    // Given a member with a message and a preference
    $user = User::factory()->create();
    DB::table('email_messages')->insert(emailRow($user->id));
    DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => 'daily_top5', 'enabled' => false, 'updated_at' => now()]);

    // When a row for an unknown user is inserted, and the member row is deleted
    $unknown = emailSqlState(fn () => DB::table('email_messages')->insert(emailRow('01ARZ3NDEKTSV4RRFFQ69G5FAV')));
    $delete = emailSqlState(fn () => DB::table('users')->where('id', $user->id)->delete());

    // Then the unknown user is refused, the delete is refused while a message exists (plain FK, no cascade)
    expect($unknown)->toBe('23503')->and($delete)->toBe('23503');

    // And once the message is gone the delete succeeds and takes only the preference rows along
    DB::table('email_messages')->where('user_id', $user->id)->delete();
    DB::table('users')->where('id', $user->id)->delete();
    expect(DB::table('email_preferences')->where('user_id', $user->id)->count())->toBe(0);
});

it('has the three indexes with the exact column order and partial predicates', function () {
    // Given the migrated schema
    // When the index definitions are read
    $indexes = DB::table('pg_indexes')->where('tablename', 'email_messages')->pluck('indexdef', 'indexname');

    // Then the claim, user and purge indexes are as documented
    expect($indexes['email_messages_due_index'])->toContain("(next_attempt_at, id) WHERE ((status)::text = ANY ((ARRAY['queued'::character varying, 'sending'::character varying])::text[]))");
    expect($indexes['email_messages_user_type_index'])->toContain('(user_id, type)')->not->toContain('WHERE');
    expect($indexes['email_messages_terminal_created_index'])->toContain("(created_at) WHERE ((status)::text = ANY ((ARRAY['sent'::character varying, 'failed'::character varying, 'suppressed'::character varying])::text[]))");
    expect($indexes['email_messages_idempotency_key_unique'])->toContain('UNIQUE')->toContain('(idempotency_key)');
});

it('round-trips a message through the model with microseconds and every column set', function () {
    // Given a factory message with a microsecond due time
    $due = now()->setMicroseconds(123456);
    $created = EmailMessage::factory()->queued($due)->create();

    // When it is read back
    $row = EmailMessage::query()->findOrFail($created->id);

    // Then casts apply and microseconds survive
    expect($row->type)->toBe(EmailType::ReviewOutcome)->and($row->status)->toBe(EmailStatus::Queued)
        ->and($row->next_attempt_at->format('u'))->toBe('123456')
        ->and($row->sent_at)->toBeNull()->and($row->attempts)->toBe(0)->and($row->payload)->toHaveKeys(['review_id', 'product_id', 'decision']);
});
