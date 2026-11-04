<?php

use App\Enums\Role;
use App\Models\AuditLog;
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

function reportQueueGet(string $query = '')
{
    return test()->getJson('/api/v1/staff/reports'.$query);
}

it('ranks by open report count first, even when the smaller group has far more reliable reporters', function () {
    // Given a target with 3 reports from new reporters and one with 2 reports from reporters with a perfect record
    signedInStaff();
    $threeReports = queueComment();
    $twoReports = queueComment();
    foreach (range(1, 3) as $i) {
        queueReport($threeReports);
    }
    foreach (range(1, 2) as $i) {
        queueReport($twoReports, queueReporter(upheld: 9));
    }

    // When staff open the queue
    $response = reportQueueGet()->assertOk();

    // Then the three-report target is first (1.5 against 1.818)
    expect(queueIds($response))->toBe([$threeReports->id, $twoReports->id]);
    expect($response->json('data.0.open_reports'))->toBe(3)->and($response->json('data.0.score'))->toBe(1.5)->and($response->json('data.1.score'))->toBe(1.818182);
});

it('breaks equal counts by the summed reliability, then by the oldest report, then by id', function () {
    // Given four targets with two open reports each: one with reliable reporters, two tied on score (the older one first), and two fully tied
    signedInStaff();
    $reliable = queueComment();
    queueReport($reliable, queueReporter(upheld: 4), '2026-10-21 11:59:00');
    queueReport($reliable, queueReporter(upheld: 4), '2026-10-21 11:59:00');
    $older = queueComment();
    $newer = queueComment();
    queueReport($newer, null, '2026-10-21 10:00:05');
    queueReport($newer, null, '2026-10-21 10:00:06');
    queueReport($older, null, '2026-10-21 10:00:01');
    queueReport($older, null, '2026-10-21 10:00:07');
    [$tieA, $tieB] = [queueComment(), queueComment()];
    foreach ([$tieA, $tieB] as $tie) {
        queueReport($tie, null, '2026-10-21 11:00:00');
        queueReport($tie, null, '2026-10-21 11:00:00');
    }

    // When staff open the queue
    $ids = queueIds(reportQueueGet()->assertOk());

    // Then reliable first (1.667), then the three score-1.0 groups by oldest report (older, then the two tied ones), and the tie by target id
    $tied = collect([$tieA->id, $tieB->id])->sort()->values()->all();
    expect($ids)->toBe([$reliable->id, $older->id, $newer->id, ...$tied]);
});

it('computes reliability as (upheld + 1) / (decided + 2) and ignores reports the system closed', function () {
    // Given one report each from a reporter with 3 upheld and 1 dismissed, one who only has system-closed reports, one who only has dismissed ones, and a new one
    signedInStaff();
    $targets = [];
    foreach (['proven' => queueReporter(upheld: 3, dismissed: 1), 'system' => queueReporter(system: 5), 'nagger' => queueReporter(dismissed: 2), 'new' => null] as $name => $reporter) {
        $targets[$name] = queueComment();
        queueReport($targets[$name], $reporter);
    }

    // When staff open the queue
    $scores = collect(reportQueueGet()->assertOk()->json('data'))->mapWithKeys(fn ($row) => [$row['target']['id'] => $row['score']]);

    // Then 4/6, 1/2 (system closes count for nothing), 1/4, 1/2
    expect($scores[$targets['proven']->id])->toBe(0.666667)->and($scores[$targets['system']->id])->toBe(0.5)
        ->and($scores[$targets['nagger']->id])->toBe(0.25)->and($scores[$targets['new']->id])->toBe(0.5);
});

it('rounds the score in SQL so equal sums tie and fall through to the age', function () {
    // Given two targets whose single reporters have the same record, and different report ages
    signedInStaff();
    $a = queueComment();
    $b = queueComment();
    queueReport($b, queueReporter(upheld: 2, dismissed: 1), '2026-10-21 09:00:00');
    queueReport($a, queueReporter(upheld: 2, dismissed: 1), '2026-10-21 10:00:00');

    // When staff open the queue
    $response = reportQueueGet()->assertOk();

    // Then the scores are equal and the older report wins
    expect(queueIds($response))->toBe([$b->id, $a->id])->and($response->json('data.0.score'))->toBe($response->json('data.1.score'));
});

it('ignores reports of unverified, suspended and deleted reporters in count, score, reasons and presence', function () {
    // Given a target reported by one eligible reporter and three ineligible ones, and a target reported only by an ineligible one
    signedInStaff();
    $mixed = queueComment();
    queueReport($mixed, null, null, 'spam');
    queueReport($mixed, User::factory()->unverified()->create(), null, 'abuse');
    queueReport($mixed, User::factory()->suspended()->create(), null, 'abuse');
    queueReport($mixed, User::factory()->create(['deleted_at' => now()]), null, 'misleading');
    $onlyIneligible = queueComment();
    queueReport($onlyIneligible, User::factory()->suspended()->create());

    // When staff open the queue
    $response = reportQueueGet()->assertOk();

    // Then only the mixed target is listed, with one open report, score 0.5 and only the eligible reason
    expect(queueIds($response))->toBe([$mixed->id]);
    expect($response->json('data.0.open_reports'))->toBe(1)->and($response->json('data.0.score'))->toBe(0.5)->and($response->json('data.0.reasons'))->toBe(['spam' => 1]);
});

it('counts a reporter who is suspended after reporting out of the queue', function () {
    // Given a reported comment whose only reporter is later suspended
    signedInStaff();
    $comment = queueComment();
    $reporter = User::factory()->create();
    queueReport($comment, $reporter);
    expect(queueIds(reportQueueGet()->assertOk()))->toBe([$comment->id]);

    // When the reporter is suspended
    $reporter->forceFill(['suspended_at' => now()])->save();

    // Then the target is gone from the queue
    expect(reportQueueGet()->assertOk()->json('data'))->toBe([]);
});

it('breaks the reasons down per reason and returns the documented row shape', function () {
    // Given a reported comment (2 spam, 1 abuse) with a known author and product
    signedInStaff();
    $product = Product::factory()->live()->create(['name' => 'Rocket', 'slug' => 'rocket']);
    $author = User::factory()->create(['handle' => 'author_x']);
    $comment = queueComment($product, ['user_id' => $author->id, 'body' => 'A <b>bold</b> claim']);
    queueReport($comment, null, '2026-10-21 08:00:00', 'spam');
    queueReport($comment, null, '2026-10-21 09:00:00', 'spam');
    queueReport($comment, null, '2026-10-21 10:00:00', 'abuse');

    // When staff open the queue
    $response = reportQueueGet()->assertOk();

    // Then the row has exactly the documented keys and values
    $row = $response->json('data.0');
    expect(array_keys($row))->toBe(['type', 'target', 'open_reports', 'reasons', 'score', 'oldest_report_at']);
    expect($row['type'])->toBe('comment')->and($row['open_reports'])->toBe(3)->and($row['reasons'])->toBe(['abuse' => 1, 'spam' => 2])
        ->and($row['oldest_report_at'])->toBe('2026-10-21T08:00:00.000000Z');
    expect(array_keys($row['target']))->toBe(['id', 'state', 'body_excerpt', 'author', 'product']);
    expect($row['target'])->toEqual(['id' => $comment->id, 'state' => 'visible', 'body_excerpt' => 'A <b>bold</b> claim', 'author' => ['id' => $author->id, 'handle' => 'author_x'], 'product' => ['id' => $product->id, 'slug' => 'rocket', 'name' => 'Rocket']]);
});

it('shows a product target with its status and maker, and never a draft', function () {
    // Given a reported live product and a reported draft (which cannot be reported through the API, so a stray row)
    signedInStaff();
    $maker = User::factory()->create(['handle' => 'maker_x']);
    $product = Product::factory()->live()->create(['maker_id' => $maker->id, 'name' => 'Rocket', 'slug' => 'rocket']);
    queueReport($product);
    queueReport(Product::factory()->create());

    // When staff open the queue
    $response = reportQueueGet()->assertOk();

    // Then only the live product is listed, with the product summary
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.target'))->toEqual(['id' => $product->id, 'slug' => 'rocket', 'name' => 'Rocket', 'status' => 'live', 'maker' => ['id' => $maker->id, 'handle' => 'maker_x']]);
});

it('keeps removed, deleted and taken-down targets listed so staff can close them, and shows the excerpt of a removed comment only', function () {
    // Given a removed comment (body kept), a comment the author deleted (body wiped), and a report of a taken-down product
    signedInStaff();
    $removed = queueComment(null, ['body' => str_repeat('é', 200)])->forceFill(['removed_at' => now(), 'removed_by' => User::factory()->role(Role::Reviewer)->create()->id, 'removed_reason' => 'x']);
    $removed->save();
    $deleted = queueComment(null, ['body' => '', 'deleted_at' => now()]);
    $takenDown = Product::factory()->takenDown()->create();
    foreach ([$removed, $deleted, $takenDown] as $target) {
        queueReport($target);
    }

    // When staff open the queue
    $rows = collect(reportQueueGet()->assertOk()->json('data'))->keyBy(fn ($row) => $row['target']['id']);

    // Then all three are listed; the removed comment keeps its first 140 characters, the deleted one has none, the product shows its status
    expect($rows)->toHaveCount(3);
    expect($rows[$removed->id]['target']['state'])->toBe('removed')->and($rows[$removed->id]['target']['body_excerpt'])->toBe(str_repeat('é', 140));
    expect($rows[$deleted->id]['target']['state'])->toBe('deleted')->and($rows[$deleted->id]['target']['body_excerpt'])->toBe('');
    expect($rows[$takenDown->id]['target']['status'])->toBe('taken_down');
});

it('shows no body for a comment whose author deleted the account, before the job has wiped it', function () {
    // Given a reported comment of a deleted account (the clean-up job has not run)
    signedInStaff();
    $comment = queueComment(null, ['user_id' => User::factory()->create(['deleted_at' => now()])->id, 'body' => 'Personal words']);
    queueReport($comment);

    // When staff open the queue
    $response = reportQueueGet()->assertOk();

    // Then the row is there, as deleted, without the text
    expect($response->json('data.0.target.state'))->toBe('deleted')->and($response->json('data.0.target.body_excerpt'))->toBe('');
    expect($response->getContent())->not->toContain('Personal words');
});

it('returns text as data: markup is not interpreted or altered', function () {
    // Given a comment and a product name with markup
    signedInStaff();
    $comment = queueComment(Product::factory()->live()->create(['name' => '<script>alert(1)</script>']), ['body' => '<img src=x onerror=alert(1)>']);
    queueReport($comment);

    // When staff open the queue
    $response = reportQueueGet()->assertOk();

    // Then it is returned verbatim as JSON strings
    expect($response->json('data.0.target.body_excerpt'))->toBe('<img src=x onerror=alert(1)>')->and($response->json('data.0.target.product.name'))->toBe('<script>alert(1)</script>');
});

it('returns at most 100 targets and drops the lowest ranked', function () {
    // Given 101 reported targets, one of them with two reports (it must stay) and the rest with one, the newest report last
    signedInStaff();
    $product = Product::factory()->live()->create();
    $top = queueComment($product);
    queueReport($top);
    queueReport($top);
    $comments = [];
    foreach (range(1, 100) as $i) {
        $comments[$i] = queueComment($product);
        queueReport($comments[$i], null, '2026-10-21 10:00:00');
    }
    // The 101st single-report target is the newest, so the lowest ranked
    $last = queueComment($product);
    queueReport($last, null, '2026-10-21 11:00:00');

    // When staff open the queue
    $ids = queueIds(reportQueueGet()->assertOk());

    // Then exactly 100 come back, the double-reported one first, and the newest single report is the one that was dropped
    expect($ids)->toHaveCount(100)->and($ids[0])->toBe($top->id)->and($ids)->not->toContain($last->id);
});

it('rejects a bad type filter with 422', function (string $query) {
    // Given a staff member
    signedInStaff();

    // When the type filter is bad
    $response = reportQueueGet($query);

    // Then 422, never 500
    $response->assertUnprocessable();
})->with(['?type=foo', '?type[]=comment', '?type[a]=comment', '?type=Comment', '?type=comment,product', '?type=', '?type=%00', '?type=1']);

it('filters by type and ignores a JSON body on the GET', function () {
    // Given a reported comment and a reported product
    signedInStaff();
    $comment = queueComment();
    $product = Product::factory()->live()->create();
    queueReport($comment);
    queueReport($product);

    // When staff filter
    $comments = reportQueueGet('?type=comment')->assertOk();
    $products = reportQueueGet('?type=product')->assertOk();
    $both = reportQueueGet()->assertOk();

    // Then each filter shows only its type, the empty one is a 422, and a JSON body on the GET is ignored
    expect(queueIds($comments))->toBe([$comment->id])->and(queueIds($products))->toBe([$product->id])->and($both->json('data'))->toHaveCount(2);
    test()->call('GET', '/api/v1/staff/reports', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['type' => 'nonsense']))->assertOk();
});

it('is empty when nothing is reported', function () {
    // Given a staff member and no reports
    signedInStaff();

    // When they open the queue
    // Then it is an empty list
    reportQueueGet()->assertOk()->assertExactJson(['data' => [], 'meta' => ['total_targets' => 0, 'truncated' => false]]);
});

it('runs a constant number of queries for a few targets or many', function () {
    // Given 3 reported comments and 3 reported products
    signedInStaff();
    $seed = function (int $n) {
        foreach (range(1, $n) as $i) {
            queueReport(queueComment());
            queueReport(Product::factory()->live()->create());
        }
    };
    $count = function () {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        reportQueueGet()->assertOk();

        return $queries;
    };
    $seed(3);
    $small = $count();

    // When 20 more of each are added
    $seed(20);
    $large = $count();

    // Then the query count did not grow
    expect($large)->toBe($small);
});

it('shows the queue to reviewers and admins and refuses everyone else', function () {
    // Given a reported comment
    queueReport(queueComment());

    // When each role asks
    // Then reviewer and admin see it, a member gets 403, a guest 401, a suspended or unverified reviewer the guard 403s
    foreach ([Role::Reviewer, Role::Admin] as $role) {
        Sanctum::actingAs(User::factory()->role($role)->create());
        reportQueueGet()->assertOk()->assertJsonCount(1, 'data');
    }
    Sanctum::actingAs(User::factory()->create());
    reportQueueGet()->assertForbidden();
    reportQueueGet('?type=nonsense')->assertForbidden();
    $this->app['auth']->forgetGuards();
    reportQueueGet()->assertUnauthorized();
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->suspended()->create());
    reportQueueGet()->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->unverified()->create());
    reportQueueGet()->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

it('never exposes reporter identity or emails in the queue', function () {
    // Given a report by a member with a recognisable email and handle
    signedInStaff();
    $reporter = User::factory()->create(['handle' => 'secret_reporter', 'email' => 'secret.reporter@example.com']);
    queueReport(queueComment(), $reporter);

    // When staff read the queue
    $content = reportQueueGet()->assertOk()->getContent();

    // Then neither appears
    expect($content)->not->toContain('secret_reporter')->not->toContain('secret.reporter')->not->toContain($reporter->id);
    expect(AuditLog::query()->count())->toBe(0);
});

/** @return list<Comment> `$n` comments on one public product */
function queueComments(int $n): array
{
    return Comment::factory()->count($n)->create(['product_id' => Product::factory()->live()->create()->id])->all();
}

it('gives a habitual false reporter weight zero: not counted, not scored, still listed in the detail', function () {
    // Given a target reported by two fake-ring reporters (3 dismissed reports each, reliability 0.2) and a target with one real report
    signedInStaff();
    $ringTarget = queueComment();
    $real = queueComment();
    $ringOne = queueReporter(dismissed: 3);
    $ringTwo = queueReporter(dismissed: 3);
    queueReport($ringTarget, $ringOne);
    queueReport($ringTarget, $ringTwo);
    queueReport($real);

    // When staff open the queue and the ring target's detail
    $response = reportQueueGet()->assertOk();

    // Then the real single report out-ranks the two ring reports, which count 0 and score 0
    expect(queueIds($response))->toBe([$real->id, $ringTarget->id]);
    expect($response->json('data.1.open_reports'))->toBe(0)->and($response->json('data.1.score'))->toEqual(0)->and($response->json('data.1.reasons'))->toBe(['spam' => 2]);
    // And the detail still lists both reports
    test()->getJson("/api/v1/staff/reports/comment/{$ringTarget->id}")->assertOk()->assertJsonCount(2, 'data.open_reports')->assertJsonPath('data.open_total', 2);
});

it('does not down-weight a reporter below three decided reports or at the 0.25 floor, and a dismissal by the system counts for nothing', function () {
    // Given reporters with 2 dismissed (0.25), with 2 dismissed and 5 system-dismissed, and with 3 upheld
    signedInStaff();
    $a = queueComment();
    $b = queueComment();
    queueReport($a, queueReporter(dismissed: 2));
    $systemHeavy = queueReporter(dismissed: 2);
    $comment = Comment::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        Report::factory()->forComment($comment)->create(['reporter_id' => $systemHeavy->id, 'status' => 'dismissed', 'handled_by' => null, 'handled_at' => now(), 'resolution_note' => 'reporter_deleted']);
    }
    queueReport($b, $systemHeavy);

    // When staff open the queue
    $rows = collect(reportQueueGet()->assertOk()->json('data'))->keyBy(fn ($row) => $row['target']['id']);

    // Then both count 1 with weight 0.25 (system dismissals are not decisions)
    expect($rows[$a->id]['open_reports'])->toBe(1)->and($rows[$a->id]['score'])->toBe(0.25)->and($rows[$b->id]['open_reports'])->toBe(1)->and($rows[$b->id]['score'])->toBe(0.25);
});

it('reserves a lane: a lone real report that ranks below the top 70 is still listed because it is among the oldest', function () {
    // Given 75 targets with two reports each, 30 newer single-report targets, and one OLD single-report target
    signedInStaff();
    [$r1, $r2, $r3, $r4] = User::factory()->count(4)->create()->all();
    foreach (queueComments(75) as $comment) {
        queueReport($comment, $r1, '2026-10-21 11:00:00');
        queueReport($comment, $r2, '2026-10-21 11:00:00');
    }
    foreach (queueComments(30) as $i => $comment) {
        queueReport($comment, $r3, '2026-10-21 11:30:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }
    $lone = queueComment();
    queueReport($lone, $r4, '2026-10-20 09:00:00');

    // When staff open the queue
    $response = reportQueueGet()->assertOk();
    $rows = $response->json('data');

    // Then exactly 100 are listed, the old lone one is among them, and the display stays in ranking order (counts never rise)
    expect($rows)->toHaveCount(100);
    expect(queueIds($response))->toContain($lone->id);
    $counts = array_column($rows, 'open_reports');
    $sorted = $counts;
    rsort($sorted);
    expect($counts)->toBe($sorted);
    expect($response->json('meta'))->toBe(['total_targets' => 106, 'truncated' => true]);
});

it('keeps a lone real report visible among 101 targets reported only by a fake ring', function () {
    // Given 101 targets each reported by two ring reporters (weight 0) and one real single-report target
    signedInStaff();
    $ringOne = queueReporter(dismissed: 3);
    $ringTwo = queueReporter(dismissed: 3);
    foreach (queueComments(101) as $comment) {
        queueReport($comment, $ringOne, '2026-10-20 08:00:00');
        queueReport($comment, $ringTwo, '2026-10-20 08:00:00');
    }
    $real = queueComment();
    queueReport($real, null, '2026-10-21 11:00:00');

    // When staff open the queue
    $response = reportQueueGet()->assertOk();

    // Then the real report is first, 100 rows are listed of 102 targets, and the response says it was cut
    expect(queueIds($response)[0])->toBe($real->id)->and($response->json('data'))->toHaveCount(100);
    expect($response->json('meta'))->toBe(['total_targets' => 102, 'truncated' => true]);
});

it('reports the target total and no truncation for a short queue, per type filter', function () {
    // Given one reported comment and two reported products
    signedInStaff();
    queueReport(queueComment());
    queueReport(Product::factory()->live()->create());
    queueReport(Product::factory()->live()->create());

    // When staff open the queue unfiltered and filtered
    // Then the totals follow the filter and nothing is truncated
    expect(reportQueueGet()->json('meta'))->toBe(['total_targets' => 3, 'truncated' => false]);
    expect(reportQueueGet('?type=product')->json('meta'))->toBe(['total_targets' => 2, 'truncated' => false]);
    expect(reportQueueGet('?type=comment')->json('meta'))->toBe(['total_targets' => 1, 'truncated' => false]);
});

it('serves the real queue SQL from indexes: the open reports from an open-status index, the reliability from (reporter_id, status)', function () {
    // Given an analysed table of many reporters, each with one open report and a few closed ones
    $reporters = User::factory()->count(400)->create()->pluck('id');
    $handler = $reporters[0];
    $comment = queueComment();
    $rows = [];
    foreach ($reporters as $n => $reporterId) {
        for ($i = 0; $i < 6; $i++) {
            $open = $i === 0;
            $rows[] = [
                'id' => (string) Str::ulid(), 'reporter_id' => $reporterId, 'reportable_type' => 'comment',
                'reportable_id' => $open ? (string) Str::ulid() : $comment->id, 'reason' => 'spam', 'details' => null,
                'status' => $open ? 'open' : 'resolved', 'handled_by' => $open ? null : $handler, 'handled_at' => $open ? null : now(),
                'resolution_note' => null, 'created_at' => now()->subSeconds($n * 6 + $i), 'updated_at' => now(),
            ];
        }
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('reports')->insert($chunk);
    }
    DB::statement('ANALYZE reports');
    signedInStaff();
    $sql = commentCaptureSql(fn () => reportQueueGet()->assertOk(), 'with');

    // When the real statement is explained with scans and sorts discouraged
    // Then the open-report scan and the reliability subquery use indexes, and no step reads the whole reports table
    expect($sql)->toHaveCount(1);
    $plan = commentPlan($sql[0]);
    expect($plan)->not->toContain('Seq Scan on reports');
    // (the partial unique index of open reports is as good a fit as (status, created_at) and the planner prefers it)
    expect($plan)->toMatch('/reports_(open_unique|status_created_at_index)/')->and($plan)->toContain('reports_reporter_id_status_index');
});
