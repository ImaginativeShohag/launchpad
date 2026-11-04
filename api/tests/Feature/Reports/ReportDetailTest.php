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

function reportDetailGet(string $type, string $id)
{
    return test()->getJson("/api/v1/staff/reports/{$type}/{$id}");
}

it('shows the full body of a removed comment with the removal data, the author and the product', function () {
    // Given a removed comment with a long body, a removal reason and a remover
    signedInStaff();
    $remover = User::factory()->role(Role::Reviewer)->create(['handle' => 'rev_one']);
    $author = User::factory()->create(['handle' => 'author_x', 'name' => 'Author X']);
    $product = Product::factory()->live()->create(['slug' => 'rocket', 'name' => 'Rocket']);
    $body = str_repeat('Long body text. ', 30);
    $comment = Comment::factory()->removed($remover, 'abusive words')->create(['product_id' => $product->id, 'user_id' => $author->id, 'body' => $body, 'created_at' => '2026-10-21 08:00:00.123456']);
    queueReport($comment);

    // When staff open the detail
    $response = reportDetailGet('comment', $comment->id)->assertOk();

    // Then the whole body is there with the removal record, and the target has exactly the documented keys
    $target = $response->json('data.target');
    expect(array_keys($target))->toBe(['id', 'parent_id', 'state', 'body', 'author', 'product', 'created_at', 'removed_at', 'removed_by', 'removed_reason']);
    expect($target['state'])->toBe('removed')->and($target['body'])->toBe($body)->and($target['removed_reason'])->toBe('abusive words')
        ->and($target['removed_by'])->toBe(['id' => $remover->id, 'handle' => 'rev_one'])->and($target['created_at'])->toBe('2026-10-21T08:00:00.123456Z');
    expect($target['author'])->toBe(['id' => $author->id, 'handle' => 'author_x', 'name' => 'Author X', 'is_suspended' => false]);
    expect($target['product'])->toBe(['id' => $product->id, 'slug' => 'rocket', 'name' => 'Rocket', 'status' => 'live']);
    expect($response->json('data.type'))->toBe('comment');
});

it('shows an author-deleted comment with an empty body and a visible one in full', function () {
    // Given a deleted comment and a visible one
    signedInStaff();
    $deleted = Comment::factory()->deletedByAuthor()->create();
    $visible = Comment::factory()->create(['body' => 'Still here']);

    // When staff open both
    // Then the deleted one has no text and the visible one its body, with null removal data
    reportDetailGet('comment', $deleted->id)->assertOk()->assertJsonPath('data.target.state', 'deleted')->assertJsonPath('data.target.body', '');
    reportDetailGet('comment', $visible->id)->assertOk()->assertJsonPath('data.target.state', 'visible')->assertJsonPath('data.target.body', 'Still here')
        ->assertJsonPath('data.target.removed_at', null)->assertJsonPath('data.target.removed_by', null);
});

it('lists the open reports with their reporters and eligibility, and the earlier closed ones with their outcome', function () {
    // Given a comment with an eligible open report, a suspended reporter's open report, and a dismissed and a system-resolved earlier report
    signedInStaff();
    $comment = Comment::factory()->create();
    $eligible = User::factory()->create(['handle' => 'good_reporter']);
    $suspended = User::factory()->suspended()->create(['handle' => 'banned_reporter']);
    queueReport($comment, $eligible, '2026-10-21 09:00:00', 'spam')->forceFill(['details' => 'Looks like an ad'])->save();
    queueReport($comment, $suspended, '2026-10-21 10:00:00', 'abuse');
    $handler = User::factory()->role(Role::Reviewer)->create(['handle' => 'rev_one']);
    $dismissed = Report::factory()->forComment($comment)->dismissed($handler)->create(['resolution_note' => 'Fine to keep', 'handled_at' => '2026-10-20 10:00:00']);
    $system = Report::factory()->forComment($comment)->systemResolved()->create(['handled_at' => '2026-10-21 07:00:00']);

    // When staff open the detail
    $response = reportDetailGet('comment', $comment->id)->assertOk();

    // Then both open reports are listed oldest first with the eligibility flag, and no outcome keys
    $open = $response->json('data.open_reports');
    expect(array_column($open, 'reporter_eligible'))->toBe([true, false]);
    expect($open[0])->toEqual(['id' => $open[0]['id'], 'reporter' => ['id' => $eligible->id, 'handle' => 'good_reporter'], 'reporter_eligible' => true, 'reason' => 'spam', 'details' => 'Looks like an ad', 'created_at' => '2026-10-21T09:00:00.000000Z']);
    expect($open[1]['reporter']['handle'])->toBe('banned_reporter')->and($open[1]['reason'])->toBe('abuse');
    // And the closed reports are newest closing first, with status, handler, time and note
    $closed = $response->json('data.closed_reports');
    expect(array_column($closed, 'id'))->toBe([$system->id, $dismissed->id]);
    expect($closed[0]['status'])->toBe('resolved')->and($closed[0]['handled_by'])->toBeNull()->and($closed[0]['resolution_note'])->toBe('deleted_by_author');
    expect($closed[1]['status'])->toBe('dismissed')->and($closed[1]['handled_by'])->toBe(['id' => $handler->id, 'handle' => 'rev_one'])
        ->and($closed[1]['handled_at'])->toBe('2026-10-20T10:00:00.000000Z')->and($closed[1]['resolution_note'])->toBe('Fine to keep');
});

it('lists at most the latest 50 closed reports', function () {
    // Given a product with 55 closed reports
    signedInStaff();
    $product = Product::factory()->live()->create();
    $handler = User::factory()->role(Role::Reviewer)->create();
    foreach (range(1, 55) as $i) {
        Report::factory()->forProduct($product)->dismissed($handler)->create(['handled_at' => now()->subMinutes($i)]);
    }

    // When staff open the detail
    // Then 50 are listed
    expect(reportDetailGet('product', $product->id)->assertOk()->json('data.closed_reports'))->toHaveCount(50);
});

it('shows a product target with its status, maker and take-down reason', function () {
    // Given a taken-down product with a report
    signedInStaff();
    $maker = User::factory()->create(['handle' => 'maker_x', 'name' => 'Maker X']);
    $product = Product::factory()->takenDown()->create(['maker_id' => $maker->id, 'name' => 'Rocket', 'slug' => 'rocket', 'tagline' => 'Fast things']);
    queueReport($product);

    // When staff open it
    $target = reportDetailGet('product', $product->id)->assertOk()->json('data.target');

    // Then the staff summary is there
    expect($target)->toEqual([
        'id' => $product->id, 'slug' => 'rocket', 'name' => 'Rocket', 'tagline' => 'Fast things', 'status' => 'taken_down',
        'launch_date' => $product->launch_date->toDateString(), 'takedown_reason' => 'Test reason',
        'maker' => ['id' => $maker->id, 'handle' => 'maker_x', 'name' => 'Maker X', 'is_suspended' => false],
    ]);
});

it('answers 404 for staff on an unknown id, a non-ULID, a bad type, a draft product and a comment on a draft', function () {
    // Given a staff member, a draft product, and a comment forced onto a draft product
    signedInStaff();
    $draft = Product::factory()->create();
    $onDraft = Comment::factory()->create(['product_id' => $draft->id]);

    // When they look each up
    // Then all are the uniform 404
    foreach ([['comment', '01ARZ3NDEKTSV4RRFFQ69G5FAV'], ['product', '01ARZ3NDEKTSV4RRFFQ69G5FAV'], ['comment', 'not-a-ulid'], ['product', '%00'], ['thing', $draft->id], ['product', $draft->id], ['comment', $onDraft->id]] as [$type, $id]) {
        reportDetailGet($type, $id)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }
});

it('answers a member 403 for an existing and an unknown id alike, and a guest 401', function () {
    // Given a reported comment
    $comment = Comment::factory()->create();
    queueReport($comment);

    // When a member looks up the existing id, an unknown one, and a bad type
    Sanctum::actingAs(User::factory()->create());
    $bodies = [
        reportDetailGet('comment', $comment->id)->assertForbidden()->json('message'),
        reportDetailGet('comment', '01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertForbidden()->json('message'),
        reportDetailGet('thing', 'not-a-ulid')->assertForbidden()->json('message'),
    ];

    // Then the answers are identical, and a guest, a suspended and an unverified reviewer get the guards
    expect(array_unique($bodies))->toHaveCount(1);
    $this->app['auth']->forgetGuards();
    reportDetailGet('comment', $comment->id)->assertUnauthorized();
    Sanctum::actingAs(User::factory()->role(Role::Admin)->suspended()->create());
    reportDetailGet('comment', $comment->id)->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    Sanctum::actingAs(User::factory()->role(Role::Admin)->unverified()->create());
    reportDetailGet('comment', $comment->id)->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

it('lets reviewers and admins in, and ignores a query type on the detail route', function () {
    // Given a reported comment
    $comment = Comment::factory()->create();
    queueReport($comment);

    // When each staff role opens it, with a stray type in the query
    foreach ([Role::Reviewer, Role::Admin] as $role) {
        Sanctum::actingAs(User::factory()->role($role)->create());

        // Then it works
        test()->getJson("/api/v1/staff/reports/comment/{$comment->id}?type=nonsense")->assertOk()->assertJsonPath('data.target.id', $comment->id);
    }
});

it('never leaks a reporter email, only handles, to staff', function () {
    // Given a report by a member with a recognisable email
    signedInStaff();
    $comment = Comment::factory()->create();
    queueReport($comment, User::factory()->create(['email' => 'secret.reporter@example.com', 'handle' => 'secret_reporter']));

    // When staff open the detail
    $content = reportDetailGet('comment', $comment->id)->assertOk()->getContent();

    // Then the handle is there and the email is not
    expect($content)->toContain('secret_reporter')->not->toContain('secret.reporter@example.com');
});

it('lists at most the oldest 200 open reports and says how many exist', function () {
    // Given a comment with 205 open reports from 205 different reporters, created one second apart
    signedInStaff();
    $comment = Comment::factory()->create();
    $rows = [];
    foreach (User::factory()->count(205)->create() as $i => $reporter) {
        $at = now()->subSeconds(1000 - $i);
        $rows[] = ['id' => (string) Str::ulid(), 'reporter_id' => $reporter->id, 'reportable_type' => 'comment', 'reportable_id' => $comment->id,
            'reason' => 'spam', 'details' => null, 'status' => 'open', 'handled_by' => null, 'handled_at' => null, 'resolution_note' => null, 'created_at' => $at, 'updated_at' => $at];
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('reports')->insert($chunk);
    }

    // When staff open the detail
    $response = reportDetailGet('comment', $comment->id)->assertOk();

    // Then 200 reports (the oldest) come back with the true total
    expect($response->json('data.open_reports'))->toHaveCount(200)->and($response->json('data.open_total'))->toBe(205);
    expect($response->json('data.open_reports.0.created_at'))->toBe($rows[0]['created_at']->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'));
});
