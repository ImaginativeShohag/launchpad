<?php

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The SQLSTATE of the exception a statement raises inside a savepoint, or null when it succeeds. */
function notifSqlState(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

/** @return array<string, mixed> a raw notifications row that satisfies every constraint unless overridden */
function notifRow(string $userId, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid7(), 'user_id' => $userId, 'type' => 'review_approved', 'data' => '{"message_key":"notifications.review_approved"}',
        'dedupe_key' => 'k:'.Str::ulid(), 'read_at' => null, 'created_at' => '2026-10-21 10:00:00.000000+00', 'updated_at' => '2026-10-21 10:00:00.000000+00',
    ], $overrides);
}

it('has the documented columns and types', function () {
    // Given the migrated schema
    // When the columns are read
    $columns = DB::table('information_schema.columns')->where('table_name', 'notifications')->get()->keyBy('column_name');

    // Then they are exactly the documented ones, with the documented types and nullability
    expect($columns->keys()->all())->toEqualCanonicalizing(['id', 'user_id', 'type', 'data', 'dedupe_key', 'read_at', 'created_at', 'updated_at']);
    expect($columns['id']->data_type)->toBe('uuid')
        ->and($columns['user_id']->data_type)->toBe('character')->and((int) $columns['user_id']->character_maximum_length)->toBe(26)
        ->and($columns['type']->data_type)->toBe('character varying')->and((int) $columns['type']->character_maximum_length)->toBe(40)
        ->and($columns['data']->data_type)->toBe('jsonb')
        ->and($columns['dedupe_key']->data_type)->toBe('character varying')->and((int) $columns['dedupe_key']->character_maximum_length)->toBe(191);
    foreach (['read_at', 'created_at', 'updated_at'] as $column) {
        expect($columns[$column]->data_type)->toBe('timestamp with time zone')->and((int) $columns[$column]->datetime_precision)->toBe(6);
    }
    expect($columns['read_at']->is_nullable)->toBe('YES');
    foreach (['id', 'user_id', 'type', 'data', 'dedupe_key', 'created_at', 'updated_at'] as $column) {
        expect($columns[$column]->is_nullable)->toBe('NO');
    }
});

it('rejects an unknown type, a non-object data value and a read_at before created_at', function (array $overrides) {
    // Given a member
    $user = User::factory()->create();

    // When a row that breaks one CHECK is inserted
    $state = notifSqlState(fn () => DB::table('notifications')->insert(notifRow($user->id, $overrides)));

    // Then Postgres refuses it with check_violation
    expect($state)->toBe('23514');
})->with([
    'unknown type' => [['type' => 'weekly_best']],
    'another unknown type' => [['type' => 'bogus']],
    'data is an array' => [['data' => '[]']],
    'data is a scalar' => [['data' => '"x"']],
    'data is null json' => [['data' => 'null']],
    'read before created' => [['read_at' => '2026-10-21 09:59:59.999999+00']],
]);

it('accepts a row read at the exact creation instant', function () {
    // Given a member
    $user = User::factory()->create();

    // When a row is read at the same microsecond it was created
    $state = notifSqlState(fn () => DB::table('notifications')->insert(notifRow($user->id, ['read_at' => '2026-10-21 10:00:00.000000+00'])));

    // Then it is accepted
    expect($state)->toBeNull();
});

it('keeps (user, dedupe_key) unique per member', function () {
    // Given a stored notification and a second member
    $user = User::factory()->create();
    $other = User::factory()->create();
    DB::table('notifications')->insert(notifRow($user->id, ['dedupe_key' => 'review:1']));

    // When the same key is inserted for the same member and for another member
    $duplicate = notifSqlState(fn () => DB::table('notifications')->insert(notifRow($user->id, ['dedupe_key' => 'review:1'])));
    $foreign = notifSqlState(fn () => DB::table('notifications')->insert(notifRow($other->id, ['dedupe_key' => 'review:1'])));

    // Then only the same member is refused (unique_violation)
    expect($duplicate)->toBe('23505')->and($foreign)->toBeNull();
});

it('refuses an unknown user and cascades the delete of a user', function () {
    // Given a notification of a member
    $user = User::factory()->create();
    DB::table('notifications')->insert(notifRow($user->id));

    // When a row for an unknown user is inserted, and the member row is deleted
    $unknown = notifSqlState(fn () => DB::table('notifications')->insert(notifRow('01ARZ3NDEKTSV4RRFFQ69G5FAV')));
    DB::table('users')->where('id', $user->id)->delete();

    // Then the unknown user is refused (foreign_key_violation) and the member's rows went with the user
    expect($unknown)->toBe('23503');
    expect(DB::table('notifications')->where('user_id', $user->id)->count())->toBe(0);
});

it('has the three indexes with the exact column order and partial predicate', function () {
    // Given the migrated schema
    // When the index definitions are read
    $indexes = DB::table('pg_indexes')->where('tablename', 'notifications')->pluck('indexdef', 'indexname');

    // Then the list, unread and purge indexes are as documented
    expect($indexes['notifications_user_index'])->toContain('(user_id, created_at DESC, id DESC)')->not->toContain('WHERE');
    expect($indexes['notifications_unread_index'])->toContain('(user_id, created_at DESC, id DESC) WHERE (read_at IS NULL)');
    expect($indexes['notifications_created_at_index'])->toContain('(created_at)')->not->toContain('WHERE');
    expect($indexes['notifications_user_dedupe_unique'])->toContain('UNIQUE')->toContain('(user_id, dedupe_key)');
});

it('round-trips a notification through the model with microseconds and every column set', function () {
    // Given a factory notification created at a microsecond instant
    $at = Carbon::parse('2026-10-21 10:00:00.123456', 'UTC');
    $created = Notification::factory()->createdAt($at)->create();

    // When it is read back
    $row = Notification::query()->findOrFail($created->id);

    // Then the id is a UUID, the instant keeps its microseconds, read_at is null and data is an array
    expect(Str::isUuid($row->id))->toBeTrue()
        ->and($row->created_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-21 10:00:00.123456')
        ->and($row->updated_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-21 10:00:00.123456')
        ->and($row->read_at)->toBeNull()
        ->and($row->type->value)->toBe('review_approved')
        ->and($row->data)->toHaveKeys(['message_key', 'product_id', 'review_id', 'params', 'href']);
});

it('builds read, typed and owned factory states', function () {
    // Given a member and the factory states
    $user = User::factory()->create();

    // When rows are created through them
    $read = Notification::factory()->forUser($user)->read()->create();
    $typed = Notification::factory()->forUser($user)->ofType(NotificationType::NewComment)->create();

    // Then the states apply and read_at never precedes created_at
    expect($read->read_at)->not->toBeNull()->and($read->read_at->gte($read->created_at))->toBeTrue()
        ->and($typed->type)->toBe(NotificationType::NewComment)
        ->and($typed->user_id)->toBe($user->id);
});

it('accepts the export_ready type', function () {
    // Given a member
    $user = User::factory()->create();

    // When an export_ready bell row is inserted
    $state = notifSqlState(fn () => DB::table('notifications')->insert(notifRow($user->id, ['type' => 'export_ready'])));

    // Then the widened CHECK accepts it
    expect($state)->toBeNull();
});
