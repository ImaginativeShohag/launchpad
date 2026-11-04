<?php

use App\Enums\Role;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';
require_once __DIR__.'/../../Support/ReportFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

function reportHistoryGet(string $query = '')
{
    return test()->getJson('/api/v1/staff/reports/history'.$query);
}

it('lists only closed reports, newest handled first, with the documented keys', function () {
    // Given an open report and three closed ones handled at different times
    signedInStaff();
    $handler = User::factory()->role(Role::Reviewer)->create(['handle' => 'rev_one']);
    $comment = Comment::factory()->create();
    queueReport($comment);
    $old = Report::factory()->forComment($comment)->dismissed($handler)->create(['handled_at' => '2026-10-20 10:00:00']);
    $new = Report::factory()->forComment($comment)->resolved($handler)->create(['handled_at' => '2026-10-21 10:00:00', 'resolution_note' => 'Removed']);
    $system = Report::factory()->forComment($comment)->systemResolved()->create(['handled_at' => '2026-10-21 09:00:00']);

    // When staff open the history
    $response = reportHistoryGet()->assertOk();

    // Then only closed reports, newest handling first
    expect(array_column($response->json('data'), 'id'))->toBe([$new->id, $system->id, $old->id]);
    expect(array_keys($response->json('data.0')))->toBe(['id', 'type', 'target_id', 'reporter', 'reporter_eligible', 'reason', 'details', 'created_at', 'status', 'handled_by', 'handled_at', 'resolution_note']);
    expect($response->json('data.0.handled_by'))->toBe(['id' => $handler->id, 'handle' => 'rev_one'])->and($response->json('data.1.handled_by'))->toBeNull();
});

it('walks the whole list by keyset with ties on handled_at, every row once in order', function () {
    // Given seven closed reports, five of them handled at the very same instant
    signedInStaff();
    $handler = User::factory()->role(Role::Reviewer)->create();
    $comment = Comment::factory()->create();
    $same = '2026-10-21 10:00:00.123456';
    foreach (range(1, 5) as $i) {
        Report::factory()->forComment($comment)->dismissed($handler)->create(['handled_at' => $same]);
    }
    Report::factory()->forComment($comment)->dismissed($handler)->create(['handled_at' => '2026-10-21 11:00:00']);
    Report::factory()->forComment($comment)->dismissed($handler)->create(['handled_at' => '2026-10-20 11:00:00']);
    $expected = Report::query()->where('status', '<>', 'open')->orderByDesc('handled_at')->orderByDesc('id')->pluck('id')->all();

    // When the pages of 2 are followed
    $ids = walkCommentPages('/api/v1/staff/reports/history?per_page=2');

    // Then every report appears exactly once, in order
    expect($ids)->toBe($expected)->and($ids)->toHaveCount(7);
});

it('keeps the type filter and page size on the next link', function () {
    // Given three closed comment reports and one closed product report
    signedInStaff();
    $handler = User::factory()->role(Role::Reviewer)->create();
    foreach (range(1, 3) as $i) {
        Report::factory()->forComment(Comment::factory()->create())->dismissed($handler)->create(['handled_at' => now()->subMinutes($i)]);
    }
    Report::factory()->forProduct(Product::factory()->live()->create())->dismissed($handler)->create();

    // When the first page of comments with 2 per page is read
    $page = reportHistoryGet('?type=comment&per_page=2')->assertOk();

    // Then the next link carries both, and the whole walk shows comment reports only
    expect($page->json('links.next'))->toContain('type=comment')->toContain('per_page=2');
    $ids = walkCommentPages('/api/v1/staff/reports/history?type=comment&per_page=2');
    expect($ids)->toHaveCount(3);
    expect(Report::query()->whereIn('id', $ids)->where('reportable_type', 'comment')->count())->toBe(3);
    expect(reportHistoryGet('?type=product')->json('data'))->toHaveCount(1);
});

it('rejects tampered cursors, bad page sizes and a bad type with 422, and treats an empty cursor as page one', function (string $query) {
    // Given a staff member
    signedInStaff();

    // When the query is bad
    // Then 422
    reportHistoryGet($query)->assertUnprocessable();
})->with([
    '?cursor=not-base64!', '?cursor=bnVsbA', '?cursor='.'eyJ4IjoxfQ', '?per_page=0', '?per_page=101', '?per_page=abc', '?per_page[]=1', '?type=foo', '?type[]=comment', '?type=', '?cursor[]=x',
]);

it('treats an empty cursor as the first page', function () {
    // Given a closed report
    signedInStaff();
    Report::factory()->dismissed(User::factory()->role(Role::Reviewer)->create())->create();

    // When the history is read with an empty cursor
    // Then it is page one
    reportHistoryGet('?cursor=')->assertOk()->assertJsonCount(1, 'data');
});

it('rejects structurally valid but wrong cursors', function () {
    // Given a staff member and cursors with the wrong keys, an impossible date and a non-ULID id
    signedInStaff();
    $encode = fn (array $payload) => rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

    // When each is used
    // Then every one is a 422 and the right shape is accepted
    foreach ([
        ['created_at' => '2026-10-21 10:00:00', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', '_pointsToNextItems' => true],
        ['handled_at' => '2026-02-31 10:00:00', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', '_pointsToNextItems' => true],
        ['handled_at' => '2026-10-21 10:00:00', 'id' => 'nope', '_pointsToNextItems' => true],
        ['handled_at' => '2026-10-21 10:00:00', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    ] as $payload) {
        reportHistoryGet('?cursor='.$encode($payload))->assertUnprocessable();
    }
    reportHistoryGet('?cursor='.$encode(['handled_at' => '2026-10-21 10:00:00', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', '_pointsToNextItems' => true]))->assertOk();
});

it('ignores a JSON body on the GET', function () {
    // Given a staff member and a closed report
    signedInStaff();
    Report::factory()->dismissed(User::factory()->role(Role::Reviewer)->create())->create();

    // When a body with a bad per_page is sent
    $response = test()->call('GET', '/api/v1/staff/reports/history', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['per_page' => 9999, 'type' => 'x']));

    // Then it is ignored
    $response->assertOk();
});

it('refuses a member with 403 before it validates the cursor, and enforces the guards', function () {
    // Given a member who sends an invalid cursor
    Sanctum::actingAs(User::factory()->create());

    // When the history is read
    // Then 403, not 422
    reportHistoryGet('?cursor=garbage!&per_page=0')->assertForbidden();
    $this->app['auth']->forgetGuards();
    reportHistoryGet()->assertUnauthorized();
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->suspended()->create());
    reportHistoryGet()->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->unverified()->create());
    reportHistoryGet()->assertForbidden()->assertJsonPath('code', 'email_unverified');
    foreach ([Role::Reviewer, Role::Admin] as $role) {
        Sanctum::actingAs(User::factory()->role($role)->create());
        reportHistoryGet()->assertOk();
    }
});

it('serves the real history SQL from reports_history_index without a Sort', function () {
    // Given an analysed table with many closed reports
    $users = User::factory()->count(20)->create()->pluck('id');
    $comment = Comment::factory()->create();
    $rows = [];
    for ($i = 0; $i < 3000; $i++) {
        $closed = $i % 5 !== 0;
        $rows[] = [
            'id' => (string) Str::ulid(), 'reporter_id' => $users[$i % 20], 'reportable_type' => 'comment',
            'reportable_id' => $closed ? $comment->id : (string) Str::ulid(), 'reason' => 'spam', 'details' => null,
            'status' => $closed ? 'dismissed' : 'open', 'handled_by' => $closed ? $users[0] : null, 'handled_at' => $closed ? now()->subSeconds($i) : null,
            'resolution_note' => null, 'created_at' => now()->subSeconds($i + 5), 'updated_at' => now(),
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('reports')->insert($chunk);
    }
    DB::statement('ANALYZE reports');
    signedInStaff();
    $sql = commentCaptureSql(fn () => reportHistoryGet('?per_page=10')->assertOk());
    $history = array_values(array_filter($sql, fn (string $s) => str_contains($s, 'from "reports"') && str_contains($s, 'limit 11')));

    // When the real statement is explained with scans and sorts discouraged
    // Then it uses the history index and has no Sort node
    expect($history)->toHaveCount(1);
    $plan = commentPlan($history[0]);
    expect($plan)->toContain('reports_history_index')->not->toContain('Sort Key')->not->toContain('Seq Scan on reports');
});
