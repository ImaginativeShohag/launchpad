<?php

use App\Models\DigestRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** The SQLSTATE of the exception a statement raises inside a savepoint, or null when it succeeds. */
function digestSqlState(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

/** @return array<string, mixed> a raw digest_runs row that satisfies every constraint unless overridden */
function digestRunRow(array $overrides = []): array
{
    return array_merge([
        'kind' => 'daily_top5', 'period_date' => '2026-10-20', 'product_ids' => '[]', 'last_user_id' => null, 'enqueued' => 0,
        'started_at' => '2026-10-21 07:00:00+00', 'completed_at' => null,
    ], $overrides);
}

it('has the documented digest_runs columns and primary key', function () {
    // Given the migrated schema
    // When the columns are read
    $columns = DB::table('information_schema.columns')->where('table_name', 'digest_runs')->get()->keyBy('column_name');

    // Then they are exactly the documented ones with the documented types and nullability
    expect($columns->keys()->all())->toEqualCanonicalizing(['kind', 'period_date', 'product_ids', 'last_user_id', 'enqueued', 'started_at', 'completed_at']);
    expect($columns['kind']->data_type)->toBe('character varying')->and((int) $columns['kind']->character_maximum_length)->toBe(16)
        ->and($columns['period_date']->data_type)->toBe('date')
        ->and($columns['product_ids']->data_type)->toBe('jsonb')
        ->and($columns['last_user_id']->data_type)->toBe('character')->and((int) $columns['last_user_id']->character_maximum_length)->toBe(26)
        ->and($columns['enqueued']->data_type)->toBe('integer')->and($columns['enqueued']->column_default)->toBe('0');
    foreach (['started_at', 'completed_at'] as $column) {
        expect($columns[$column]->data_type)->toBe('timestamp with time zone')->and((int) $columns[$column]->datetime_precision)->toBe(6);
    }
    foreach (['last_user_id', 'completed_at'] as $column) {
        expect($columns[$column]->is_nullable)->toBe('YES');
    }
    foreach (['kind', 'period_date', 'product_ids', 'enqueued', 'started_at'] as $column) {
        expect($columns[$column]->is_nullable)->toBe('NO');
    }
    $primary = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'digest_runs'::regclass AND contype = 'p'");
    expect($primary->def)->toBe('PRIMARY KEY (kind, period_date)');
});

it('rejects each row that breaks a CHECK of digest_runs', function (array $overrides) {
    // Given / When a row that breaks one CHECK is inserted
    $state = digestSqlState(fn () => DB::table('digest_runs')->insert(digestRunRow($overrides)));

    // Then Postgres refuses it with check_violation
    expect($state)->toBe('23514');
})->with([
    'unknown kind' => [['kind' => 'monthly']],
    'product ids is an object' => [['product_ids' => '{}']],
    'product ids is a scalar' => [['product_ids' => '"x"']],
    'six product ids' => [['product_ids' => '["a","b","c","d","e","f"]']],
    'negative enqueued' => [['enqueued' => -1]],
]);

it('accepts a run with zero to five ids, and refuses a duplicate (kind, period)', function () {
    // Given runs of both kinds
    foreach (['[]', '["a"]', '["a","b","c","d","e"]'] as $i => $ids) {
        DB::table('digest_runs')->insert(digestRunRow(['product_ids' => $ids, 'period_date' => '2026-10-1'.$i]));
    }
    DB::table('digest_runs')->insert(digestRunRow(['kind' => 'weekly_best']));

    // When the same (kind, period) is inserted again
    $duplicate = digestSqlState(fn () => DB::table('digest_runs')->insert(digestRunRow(['period_date' => '2026-10-10'])));

    // Then it is a unique_violation, and the same period of the other kind is fine
    expect($duplicate)->toBe('23505')->and(DB::table('digest_runs')->count())->toBe(4);
});

it('indexes the period date for the retention purge', function () {
    // Given the migrated schema
    // When the index definitions are read
    $indexes = DB::table('pg_indexes')->where('tablename', 'digest_runs')->pluck('indexdef', 'indexname');

    // Then the purge index covers period_date alone
    expect($indexes['digest_runs_period_date_index'])->toContain('(period_date)')->not->toContain('WHERE');
});

it('round-trips a run through the read-only model', function () {
    // Given a stored run with a cursor and a completion time
    DB::table('digest_runs')->insert(digestRunRow(['product_ids' => '["a","b"]', 'last_user_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'enqueued' => 7, 'completed_at' => '2026-10-21 07:05:00.123456+00']));

    // When it is read through the model
    $run = DigestRun::query()->where('kind', 'daily_top5')->firstOrFail();

    // Then casts apply
    expect($run->product_ids)->toBe(['a', 'b'])->and($run->enqueued)->toBe(7)->and($run->last_user_id)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($run->period_date->toDateString())->toBe('2026-10-20')->and($run->completed_at->format('Y-m-d H:i:s.u'))->toBe('2026-10-21 07:05:00.123456');
});
