<?php

use App\Enums\ReportStatus;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The SQLSTATE of the exception a statement raises inside a savepoint, or null when it succeeds. */
function communitySqlState(Closure $callback): ?string
{
    try {
        DB::transaction($callback);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

/** @return array<string, mixed> a raw reports row that satisfies every constraint unless overridden */
function reportRow(string $reporterId, string $targetId, array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::ulid(), 'reporter_id' => $reporterId, 'reportable_type' => 'comment', 'reportable_id' => $targetId,
        'reason' => 'spam', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

it('has the documented columns on the follow tables and reports', function (string $table, array $columns) {
    // Given the migrated schema
    // When the column names are read
    $names = DB::table('information_schema.columns')->where('table_name', $table)->pluck('column_name')->all();

    // Then they are exactly the documented ones
    expect($names)->toEqualCanonicalizing($columns);
})->with([
    'user_follows' => ['user_follows', ['follower_id', 'followed_id', 'created_at']],
    'topic_follows' => ['topic_follows', ['user_id', 'topic_id', 'created_at']],
    'reports' => ['reports', ['id', 'reporter_id', 'reportable_type', 'reportable_id', 'reason', 'details', 'status', 'handled_by', 'handled_at', 'resolution_note', 'created_at', 'updated_at']],
]);

it('uses microsecond timestamptz columns for the follow and report time columns', function (string $table, string $column) {
    // Given the migrated schema
    // When the column type is read
    $type = DB::table('information_schema.columns')->where('table_name', $table)->where('column_name', $column)->first(['data_type', 'datetime_precision']);

    // Then it is timestamp with time zone with 6 fractional digits
    expect($type->data_type)->toBe('timestamp with time zone')->and((int) $type->datetime_precision)->toBe(6);
})->with([
    ['user_follows', 'created_at'], ['topic_follows', 'created_at'], ['reports', 'created_at'], ['reports', 'updated_at'], ['reports', 'handled_at'],
]);

it('keys the follow tables by their pair and indexes the other side', function () {
    // Given the migrated schema
    // When the index definitions are read
    $users = DB::table('pg_indexes')->where('tablename', 'user_follows')->pluck('indexdef', 'indexname');
    $topics = DB::table('pg_indexes')->where('tablename', 'topic_follows')->pluck('indexdef', 'indexname');

    // Then the primary keys are the pairs and the keyset and reverse lookups are indexed
    expect($users['user_follows_pkey'])->toContain('(follower_id, followed_id)');
    expect($users['user_follows_followed_id_index'])->toContain('(followed_id)');
    expect($users['user_follows_follower_created_index'])->toContain('(follower_id, created_at DESC, followed_id DESC)')->not->toContain('WHERE');
    expect($topics['topic_follows_pkey'])->toContain('(user_id, topic_id)');
    expect($topics['topic_follows_topic_id_index'])->toContain('(topic_id)');
});

it('refuses a self-follow with 23514, a duplicate with 23505 and unknown references with 23503', function () {
    // Given two members and a topic
    $a = User::factory()->create();
    $b = User::factory()->create();
    $topic = Topic::factory()->create();
    DB::table('user_follows')->insert(['follower_id' => $a->id, 'followed_id' => $b->id]);
    DB::table('topic_follows')->insert(['user_id' => $a->id, 'topic_id' => $topic->id]);
    $unknown = (string) Str::ulid();

    // When each bad row is inserted
    $states = [
        communitySqlState(fn () => DB::table('user_follows')->insert(['follower_id' => $a->id, 'followed_id' => $a->id])),
        communitySqlState(fn () => DB::table('user_follows')->insert(['follower_id' => $a->id, 'followed_id' => $b->id])),
        communitySqlState(fn () => DB::table('user_follows')->insert(['follower_id' => $a->id, 'followed_id' => $unknown])),
        communitySqlState(fn () => DB::table('user_follows')->insert(['follower_id' => $unknown, 'followed_id' => $a->id])),
        communitySqlState(fn () => DB::table('topic_follows')->insert(['user_id' => $a->id, 'topic_id' => $topic->id])),
        communitySqlState(fn () => DB::table('topic_follows')->insert(['user_id' => $a->id, 'topic_id' => $unknown])),
        communitySqlState(fn () => DB::table('topic_follows')->insert(['user_id' => $unknown, 'topic_id' => $topic->id])),
    ];

    // Then the matching constraint refuses each
    expect($states)->toBe(['23514', '23505', '23503', '23503', '23505', '23503', '23503']);
});

it('lets the reverse follow exist beside the forward one', function () {
    // Given two members following each other
    $a = User::factory()->create();
    $b = User::factory()->create();

    // When both directions are inserted
    $state = communitySqlState(function () use ($a, $b) {
        DB::table('user_follows')->insert(['follower_id' => $a->id, 'followed_id' => $b->id]);
        DB::table('user_follows')->insert(['follower_id' => $b->id, 'followed_id' => $a->id]);
    });

    // Then both rows are accepted
    expect($state)->toBeNull()->and(DB::table('user_follows')->count())->toBe(2);
});

it('refuses a report that breaks a check constraint with SQLSTATE 23514', function (array $override) {
    // Given a reporter, a target and a row that breaks one constraint
    $reporter = User::factory()->create();
    $comment = Comment::factory()->create();

    // When it is inserted
    $state = communitySqlState(fn () => DB::table('reports')->insert(reportRow($reporter->id, $comment->id, $override)));

    // Then the CHECK (not a column type) refuses it
    expect($state)->toBe('23514');
})->with([
    'bad type' => [['reportable_type' => 'user']],
    'bad reason' => [['reason' => 'rude']],
    'bad status' => [['status' => 'pending']],
    'open with handled_at' => [['status' => 'open', 'handled_at' => '2026-10-21 10:00:00']],
    'resolved without handled_at' => [['status' => 'resolved']],
    'dismissed without handled_at' => [['status' => 'dismissed']],
]);

it('accepts a closed report that has handled_at, with or without a handler', function (array $override) {
    // Given a reporter and a target
    $reporter = User::factory()->create();
    $comment = Comment::factory()->create();
    $override = array_map(fn ($v) => $v === '@handler' ? User::factory()->create()->id : $v, $override);

    // When the closed row is inserted
    $state = communitySqlState(fn () => DB::table('reports')->insert(reportRow($reporter->id, $comment->id, $override)));

    // Then it goes in
    expect($state)->toBeNull();
})->with([
    'resolved by staff' => [['status' => 'resolved', 'handled_at' => '2026-10-21 10:00:00', 'handled_by' => '@handler']],
    'resolved by the system' => [['status' => 'resolved', 'handled_at' => '2026-10-21 10:00:00']],
    'dismissed' => [['status' => 'dismissed', 'handled_at' => '2026-10-21 10:00:00', 'handled_by' => '@handler']],
]);

it('refuses a details text of 1001 characters with 22001 and accepts 1000', function () {
    // Given a reporter and a target
    $reporter = User::factory()->create();
    $a = Comment::factory()->create();
    $b = Comment::factory()->create();

    // When details of 1001 and of 1000 characters are inserted
    $long = communitySqlState(fn () => DB::table('reports')->insert(reportRow($reporter->id, $a->id, ['details' => str_repeat('x', 1001)])));
    $ok = communitySqlState(fn () => DB::table('reports')->insert(reportRow($reporter->id, $b->id, ['details' => str_repeat('é', 1000)])));

    // Then the varchar length refuses the first (before any CHECK) and the second is stored
    expect($long)->toBe('22001')->and($ok)->toBeNull();
});

it('allows one open report per reporter and target, but not two', function () {
    // Given a reporter with an open report on a comment
    $reporter = User::factory()->create();
    $other = User::factory()->create();
    $comment = Comment::factory()->create();
    DB::table('reports')->insert(reportRow($reporter->id, $comment->id));

    // When the same reporter opens another, and a different reporter opens one
    $duplicate = communitySqlState(fn () => DB::table('reports')->insert(reportRow($reporter->id, $comment->id)));
    $different = communitySqlState(fn () => DB::table('reports')->insert(reportRow($other->id, $comment->id)));

    // Then only the duplicate is refused (23505)
    expect($duplicate)->toBe('23505')->and($different)->toBeNull();
});

it('lets an open report sit beside a closed one by the same reporter on the same target', function (string $closed) {
    // Given a reporter whose earlier report on the target was closed
    $reporter = User::factory()->create();
    $comment = Comment::factory()->create();
    DB::table('reports')->insert(reportRow($reporter->id, $comment->id, ['status' => $closed, 'handled_at' => now()]));

    // When they open a new report on it
    $state = communitySqlState(fn () => DB::table('reports')->insert(reportRow($reporter->id, $comment->id)));

    // Then the partial unique index does not block it
    expect($state)->toBeNull();
})->with(['resolved', 'dismissed']);

it('creates the report indexes with the exact partial predicates and column order', function () {
    // Given the migrated schema
    // When the index definitions are read
    $indexes = DB::table('pg_indexes')->where('tablename', 'reports')->pluck('indexdef', 'indexname');

    // Then the open-only unique index, the history index and the plain ones are as designed
    expect($indexes['reports_open_unique'])->toContain('UNIQUE INDEX')->toContain("(reporter_id, reportable_type, reportable_id) WHERE ((status)::text = 'open'::text)");
    expect($indexes['reports_history_index'])->toContain("(handled_at DESC, id DESC) WHERE ((status)::text <> 'open'::text)");
    expect($indexes['reports_status_created_at_index'])->toContain('(status, created_at)');
    expect($indexes['reports_reportable_type_reportable_id_index'])->toContain('(reportable_type, reportable_id)');
    expect($indexes['reports_reporter_id_status_index'])->toContain('(reporter_id, status)');
});

it('has plain foreign keys on reporter and handler, none on the polymorphic target', function () {
    // Given a reporter and a handler
    $reporter = User::factory()->create();
    $unknown = (string) Str::ulid();

    // When rows point at unknown users, and at an unknown target
    $badReporter = communitySqlState(fn () => DB::table('reports')->insert(reportRow($unknown, $unknown)));
    $badHandler = communitySqlState(fn () => DB::table('reports')->insert(reportRow($reporter->id, $unknown, ['status' => 'resolved', 'handled_at' => now(), 'handled_by' => $unknown])));
    $anyTarget = communitySqlState(fn () => DB::table('reports')->insert(reportRow($reporter->id, $unknown)));

    // Then the user keys refuse (23503) and the target needs no row
    expect([$badReporter, $badHandler, $anyTarget])->toBe(['23503', '23503', null]);
});

it('lets every Report factory state satisfy the checks and cast its enums', function () {
    // Given the factory states
    // When each is created
    $open = Report::factory()->create();
    $resolved = Report::factory()->resolved()->create();
    $dismissed = Report::factory()->dismissed(User::factory()->create())->create();
    $system = Report::factory()->systemResolved()->create();
    $product = Report::factory()->forProduct(Product::factory()->live()->create())->create();

    // Then they all inserted with the intended status, handler and note
    expect($open->status)->toBe(ReportStatus::Open)->and($open->handled_at)->toBeNull()->and($open->resolution_note)->toBeNull();
    expect($resolved->status)->toBe(ReportStatus::Resolved)->and($resolved->handled_by)->not->toBeNull()->and($resolved->handled_at)->not->toBeNull();
    expect($dismissed->status)->toBe(ReportStatus::Dismissed)->and($dismissed->handled_at)->not->toBeNull();
    expect($system->status)->toBe(ReportStatus::Resolved)->and($system->handled_by)->toBeNull()->and($system->resolution_note)->toBe('deleted_by_author');
    expect($product->reportable_type)->toBe('product');
    expect($open->fresh()->reason->value)->toBe('spam');
});

it('filters with Report::open()', function () {
    // Given one open and one resolved report
    $open = Report::factory()->create();
    Report::factory()->resolved()->create();

    // When the scope runs
    // Then only the open one is returned
    expect(Report::query()->open()->pluck('id')->all())->toBe([$open->id]);
});

it('keeps microseconds on report timestamps', function () {
    // Given a report created at a microsecond instant
    $at = now()->microsecond(654321);
    $report = Report::factory()->create(['created_at' => $at]);

    // When it is reloaded
    // Then the microseconds survived
    expect($report->fresh()->created_at->micro)->toBe(654321);
});
