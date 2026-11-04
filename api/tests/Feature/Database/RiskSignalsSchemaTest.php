<?php

use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A valid raw row for `risk_signals`, with overrides. */
function riskSignalRow(array $overrides = []): array
{
    return [
        'id' => (string) Str::ulid(), 'kind' => 'shared_device', 'user_id' => User::factory()->create()->id, 'product_id' => null,
        'weight' => 40, 'details' => '{}', 'dedupe_key' => 'k:'.Str::ulid(), 'detected_at' => now(),
        'reviewed_by' => null, 'reviewed_at' => null, 'outcome' => null, 'review_note' => null, ...$overrides,
    ];
}

function riskSignalSqlState(array $overrides): ?string
{
    // The failing statement runs in a savepoint so the test transaction stays usable.
    try {
        DB::transaction(fn () => DB::table('risk_signals')->insert(riskSignalRow($overrides)));
    } catch (QueryException $e) {
        return (string) $e->errorInfo[0];
    }

    return null;
}

it('has the documented columns', function () {
    // Given the migrated schema
    // When the columns are read
    $columns = DB::table('information_schema.columns')->where('table_name', 'risk_signals')->get()->keyBy('column_name');

    // Then the types, nullability and precision match the design
    expect($columns->keys()->sort()->values()->all())->toBe(['dedupe_key', 'details', 'detected_at', 'id', 'kind', 'outcome', 'product_id', 'review_note', 'reviewed_at', 'reviewed_by', 'user_id', 'weight']);
    expect($columns['detected_at']->data_type)->toBe('timestamp with time zone')->and((int) $columns['detected_at']->datetime_precision)->toBe(0);
    expect($columns['reviewed_at']->data_type)->toBe('timestamp with time zone')->and((int) $columns['reviewed_at']->datetime_precision)->toBe(0);
    expect($columns['weight']->data_type)->toBe('smallint')->and($columns['details']->data_type)->toBe('jsonb');
    expect($columns['user_id']->is_nullable)->toBe('YES')->and($columns['product_id']->is_nullable)->toBe('YES');
    expect($columns['kind']->character_maximum_length)->toBe(24)->and($columns['dedupe_key']->character_maximum_length)->toBe(160);
    expect($columns['outcome']->character_maximum_length)->toBe(20)->and($columns['review_note']->character_maximum_length)->toBe(255);
});

it('refuses each row that breaks a CHECK with 23514', function (array $overrides) {
    // Given a row that breaks one rule
    // When it is inserted
    // Then Postgres refuses it with a check violation
    expect(riskSignalSqlState($overrides))->toBe('23514');
})->with([
    'unknown kind' => [['kind' => 'made_up']],
    'unknown outcome' => [['outcome' => 'maybe', 'reviewed_by' => 'will-be-replaced', 'reviewed_at' => '2026-10-21 12:00:00']],
    'weight below zero' => [['weight' => -1]],
    'weight above 100' => [['weight' => 101]],
    'no subject' => [['user_id' => null, 'product_id' => null]],
    'reviewed_by without the rest' => [['reviewed_by' => 'will-be-replaced']],
    'reviewed_at without the rest' => [['reviewed_at' => '2026-10-21 12:00:00']],
    'outcome alone' => [['outcome' => 'confirmed']],
    'reviewed_by and reviewed_at without outcome' => [['reviewed_by' => 'will-be-replaced', 'reviewed_at' => '2026-10-21 12:00:00']],
]);

it('accepts the weight bounds and a complete review', function () {
    // Given rows at the bounds and a fully reviewed row
    $reviewer = User::factory()->create();

    // When they are inserted
    // Then none is refused
    expect(riskSignalSqlState(['weight' => 0]))->toBeNull();
    expect(riskSignalSqlState(['weight' => 100]))->toBeNull();
    expect(riskSignalSqlState(['reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'outcome' => 'dismissed']))->toBeNull();
});

it('rejects an over-long dedupe key before any CHECK with 22001', function () {
    // Given a key of 161 characters
    // When it is inserted
    // Then varchar(160) refuses it
    expect(riskSignalSqlState(['dedupe_key' => str_repeat('a', 161)]))->toBe('22001');
    expect(riskSignalSqlState(['dedupe_key' => str_repeat('a', 160)]))->toBeNull();
});

it('keeps one row per dedupe key and lets ON CONFLICT DO NOTHING skip the duplicate', function () {
    // Given a stored signal
    $first = riskSignalRow(['dedupe_key' => 'same:key']);
    DB::table('risk_signals')->insert($first);

    // When the same key is inserted again, plainly and with ON CONFLICT
    // Then the plain insert is a unique violation and the guarded one returns no rows
    expect(riskSignalSqlState(['dedupe_key' => 'same:key', 'user_id' => $first['user_id']]))->toBe('23505');
    $rows = DB::select('INSERT INTO risk_signals (id, kind, user_id, weight, details, dedupe_key, detected_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (dedupe_key) DO NOTHING RETURNING id', [(string) Str::ulid(), 'shared_device', $first['user_id'], 40, '{}', 'same:key', now()]);
    expect($rows)->toBe([])->and(DB::table('risk_signals')->where('dedupe_key', 'same:key')->count())->toBe(1);
});

it('enforces the foreign keys with 23503', function () {
    // Given ids that do not exist
    $missing = (string) Str::ulid();
    $reviewerRow = ['reviewed_at' => now(), 'outcome' => 'confirmed'];

    // When rows point at them
    // Then each is refused
    expect(riskSignalSqlState(['user_id' => $missing]))->toBe('23503');
    expect(riskSignalSqlState(['product_id' => $missing]))->toBe('23503');
    expect(riskSignalSqlState([...$reviewerRow, 'reviewed_by' => $missing]))->toBe('23503');
});

it('has the six partial and composite indexes with the exact column order and predicate', function () {
    // Given the migrated schema
    $defs = DB::table('pg_indexes')->where('tablename', 'risk_signals')->pluck('indexdef', 'indexname')->all();

    // When the definitions are read
    // Then each index has its columns, directions and predicate (the purge and the queries use the same text)
    expect($defs['risk_signals_user_id_detected_at_index'])->toContain('(user_id, detected_at)');
    expect($defs['risk_signals_product_id_detected_at_index'])->toContain('(product_id, detected_at)');
    expect($defs['risk_signals_open_queue_index'])->toContain('(weight DESC, detected_at DESC, id DESC)')->toContain('WHERE (reviewed_at IS NULL)');
    expect($defs['risk_signals_unreviewed_detected_index'])->toContain('(detected_at)')->toContain('WHERE (reviewed_at IS NULL)')->not->toContain('DESC');
    expect($defs['risk_signals_reviewed_index'])->toContain('(reviewed_at DESC, id DESC)')->toContain('WHERE (reviewed_at IS NOT NULL)');
    expect($defs['risk_signals_detected_index'])->toContain('(detected_at DESC, id DESC)')->not->toContain('WHERE');
    expect($defs['risk_signals_dedupe_key_unique'])->toContain('UNIQUE')->toContain('(dedupe_key)');
});

it('indexes only members with a risk score, worst first', function () {
    // Given the migrated schema
    $def = DB::table('pg_indexes')->where('indexname', 'users_risk_score_index')->value('indexdef');

    // When the definition is read
    // Then it is partial on risk_score > 0 and ends in id DESC (a row with a score of 0 is not in it)
    expect($def)->toContain('(risk_score DESC, id DESC)')->toContain('WHERE (risk_score > 0)');
});

it('builds factory signals that satisfy every CHECK', function () {
    // Given the factory and its states
    // When rows are created
    $open = RiskSignal::factory()->create();
    $userOnly = RiskSignal::factory()->forUserOnly()->create();
    $productOnly = RiskSignal::factory()->forProductOnly()->kind(RiskSignalKind::VoteBurst, 0)->create();
    $confirmed = RiskSignal::factory()->reviewed(RiskOutcome::Confirmed)->create();
    $dismissed = RiskSignal::factory()->reviewed(RiskOutcome::Dismissed, User::factory()->create())->detectedAt(now()->subDays(3))->create();

    // Then they are stored and read back with their statuses
    expect($open->status())->toBe('open')->and($userOnly->product_id)->toBeNull()->and($productOnly->user_id)->toBeNull()->and($productOnly->weight)->toBe(0);
    expect($confirmed->status())->toBe('confirmed')->and($dismissed->status())->toBe('dismissed');
    expect(RiskSignal::query()->open()->count())->toBe(3);
    expect(RiskSignal::query()->findOrFail($open->id)->product()->first())->toBeInstanceOf(Product::class);
    expect(RiskSignal::query()->with('user', 'reviewer')->findOrFail($dismissed->id)->reviewer->id)->toBe($dismissed->reviewed_by);
});
