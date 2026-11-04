<?php

use App\Actions\Comment\CreateComment;
use App\Actions\Comment\DeleteComment;
use App\Actions\Report\ResolveReport;
use App\Enums\ProductStatus;
use App\Enums\ReportAction;
use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Events\ProductTakenDown;
use App\Exceptions\ProductRuleViolation;
use App\Models\AuditLog;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));
afterEach(function () {
    AuditLog::flushEventListeners();
    Report::flushEventListeners();
});

function rrPost(string $type, string $id, array $payload)
{
    return test()->postJson("/api/v1/staff/reports/{$type}/{$id}/resolve", $payload);
}

/** A live product with a counted top-level comment (made through the action, so the counters are real) and `$reports` open reports of it. */
function rrReportedComment(int $reports = 2, ?Comment $parentOf = null): Comment
{
    $product = $parentOf?->product ?? commentable();
    $comment = app(CreateComment::class)->handle(User::factory()->create(), $product->id, 'A reported comment with enough words', $parentOf?->id);
    for ($i = 0; $i < $reports; $i++) {
        Report::factory()->forComment($comment)->create();
    }

    return $comment;
}

function rrReportedProduct(int $reports = 2, ?Product $product = null): Product
{
    $product ??= commentable();
    for ($i = 0; $i < $reports; $i++) {
        Report::factory()->forProduct($product)->create();
    }

    return $product;
}

function rrAudit(): array
{
    return AuditLog::query()->orderBy('created_at')->orderBy('id')->get()->map(fn (AuditLog $log) => $log->action)->all();
}

it('removes a reported top-level comment: removal columns, counter, reports resolved, exactly two audit rows', function () {
    // Given a reviewer and a reported comment with a visible reply, and a note longer than the 255-char reason column
    $reviewer = signedInStaff(Role::Reviewer);
    $comment = rrReportedComment(2);
    $reply = app(CreateComment::class)->handle(User::factory()->create(), $comment->product_id, 'A reply that keeps the thread', $comment->id);
    $note = str_repeat('x', 300).' end';

    // When the reviewer removes it
    $response = rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => $note]);

    // Then 200 with the outcome, and the comment is removed by the reviewer with the reason cut to 255
    $response->assertOk()->assertExactJson(['data' => ['closed' => 2, 'action' => 'remove_comment', 'type' => 'comment', 'target_id' => $comment->id, 'target_changed' => true]]);
    $row = $comment->fresh();
    expect($row->removed_at)->not->toBeNull()->and($row->removed_by)->toBe($reviewer->id)->and($row->removed_reason)->toBe(str_repeat('x', 255))
        ->and($row->body)->toBe('A reported comment with enough words')->and($row->updated_at->greaterThanOrEqualTo($row->created_at))->toBeTrue();
    // And the counter fell by one (the reply stays counted), and both reports are resolved with the full note
    expect(productCommentCounters($comment->product_id))->toBe([1, 1]);
    foreach (Report::query()->get() as $report) {
        expect($report->status)->toBe(ReportStatus::Resolved)->and($report->handled_by)->toBe($reviewer->id)->and($report->handled_at)->not->toBeNull()->and($report->resolution_note)->toBe($note);
    }
    // And exactly two audit rows with ids and the reason only
    expect(rrAudit())->toBe(['comment.removed', 'report.resolved']);
    $removed = AuditLog::query()->where('action', 'comment.removed')->sole();
    expect($removed->actor_id)->toBe($reviewer->id)->and($removed->subject_id)->toBe($comment->id)->and($removed->meta)->toEqual(['product_id' => $comment->product_id, 'reason' => str_repeat('x', 255)]);
    $resolved = AuditLog::query()->where('action', 'report.resolved')->sole();
    expect($resolved->subject_id)->toBe($comment->id)->and($resolved->meta)->toEqual(['type' => 'comment', 'reports' => 2, 'action' => 'remove_comment', 'note' => $note]);
    assertCommentCountersConsistent();
    expect($reply->fresh()->removed_at)->toBeNull();
});

it('shows a removed comment as a placeholder only while it has replies, and its author can no longer edit it', function () {
    // Given a reported comment with one reply and another reported comment without replies
    signedInStaff(Role::Reviewer);
    $withReply = rrReportedComment(1);
    app(CreateComment::class)->handle(User::factory()->create(), $withReply->product_id, 'The surviving reply', $withReply->id);
    $alone = rrReportedComment(1);

    // When staff remove both
    rrPost('comment', $withReply->id, ['action' => 'remove_comment', 'note' => 'abusive'])->assertOk();
    rrPost('comment', $alone->id, ['action' => 'remove_comment', 'note' => 'abusive'])->assertOk();

    // Then the public list shows the first as a body-less removed placeholder with its reply, and not the second
    $slug = $withReply->product->slug;
    $list = test()->getJson("/api/v1/products/{$slug}/comments")->assertOk();
    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$withReply->id]);
    expect($list->json('data.0.state'))->toBe('removed')->and($list->json('data.0.body'))->toBeNull()->and($list->json('data.0.author'))->toBeNull()
        ->and($list->json('data.0.replies.0.body'))->toBe('The surviving reply');
    expect($list->getContent())->not->toContain('A reported comment with enough words')->not->toContain('abusive');
    // And the author gets the uniform 404 on edit and delete
    Sanctum::actingAs(User::query()->findOrFail($withReply->user_id));
    test()->patchJson("/api/v1/comments/{$withReply->id}", ['body' => 'A new body here'])->assertNotFound();
    test()->deleteJson("/api/v1/comments/{$withReply->id}")->assertNotFound();
});

it('removes a reply: the parent loses a reply and the product a comment', function () {
    // Given a reported reply under a top-level comment
    signedInStaff(Role::Reviewer);
    $top = rrReportedComment(0);
    $reply = rrReportedComment(1, $top);
    expect(productCommentCounters($top->product_id))->toBe([2, 2]);
    expect(DB::table('comments')->where('id', $top->id)->value('replies_count'))->toBe(1);

    // When staff remove the reply
    rrPost('comment', $reply->id, ['action' => 'remove_comment', 'note' => 'spam links'])->assertOk()->assertJsonPath('data.target_changed', true);

    // Then both counters moved and the invariant holds
    expect(productCommentCounters($top->product_id))->toBe([1, 1]);
    expect(DB::table('comments')->where('id', $top->id)->value('replies_count'))->toBe(0);
    assertCommentCountersConsistent();
});

it('takes a live product down as an admin: status, audit rows, one event, reports resolved', function () {
    // Given an admin and a reported live product
    Event::fake([ProductTakenDown::class]);
    $admin = signedInStaff(Role::Admin);
    $product = rrReportedProduct(2);

    // When the admin takes it down through the report
    $response = rrPost('product', $product->id, ['action' => 'take_down', 'note' => 'Scam listing']);

    // Then the product is taken down with the reason, the reports are resolved, and the audit has the take-down and the closing
    $response->assertOk()->assertJsonPath('data.closed', 2)->assertJsonPath('data.target_changed', true);
    $fresh = $product->fresh();
    expect($fresh->status)->toBe(ProductStatus::TakenDown)->and($fresh->takedown_reason)->toBe('Scam listing')->and($fresh->taken_down_by)->toBe($admin->id);
    expect(Report::query()->where('status', 'resolved')->where('handled_by', $admin->id)->count())->toBe(2);
    expect(rrAudit())->toBe(['product.taken_down', 'report.resolved']);
    expect(AuditLog::query()->where('action', 'report.resolved')->sole()->meta)->toEqual(['type' => 'product', 'reports' => 2, 'action' => 'take_down', 'note' => 'Scam listing']);
    Event::assertDispatchedTimes(ProductTakenDown::class, 1);
});

it('refuses take_down to a reviewer before it validates anything, and still lets that reviewer remove or dismiss', function () {
    // Given a reviewer and a reported product
    signedInStaff(Role::Reviewer);
    $product = rrReportedProduct(1);

    // When the reviewer asks for a take-down with an invalid body, and with a valid one
    $invalid = rrPost('product', $product->id, ['action' => 'take_down', 'note' => ['x']]);
    $valid = rrPost('product', $product->id, ['action' => 'take_down', 'note' => 'Scam listing']);
    $unknown = rrPost('product', '01ARZ3NDEKTSV4RRFFQ69G5FAV', ['action' => 'take_down']);

    // Then all are 403 (no 422, no existence oracle), nothing changed, and a dismissal by the same reviewer works
    foreach ([$invalid, $valid, $unknown] as $response) {
        $response->assertForbidden();
    }
    expect($product->fresh()->status)->toBe(ProductStatus::Live)->and(Report::query()->where('status', 'open')->count())->toBe(1);
    rrPost('product', $product->id, ['action' => 'dismiss'])->assertOk()->assertJsonPath('data.closed', 1);
});

it('answers 409 invalid_status for a product that is neither live nor taken down, and 404 for a draft, closing nothing', function (string $state) {
    // Given a reported product in a non-live status
    signedInStaff(Role::Admin);
    $product = match ($state) {
        'draft' => Product::factory()->create(),
        'pending' => Product::factory()->pending()->create(),
        'approved' => Product::factory()->approved()->create(),
        'scheduled' => Product::factory()->scheduled()->create(),
        'rejected' => Product::factory()->rejected()->create(),
        'changes_requested' => Product::factory()->changesRequested()->create(),
    };
    rrReportedProduct(1, $product);

    // When the admin tries to take it down
    $response = rrPost('product', $product->id, ['action' => 'take_down', 'note' => 'Scam listing']);

    // Then a draft is the uniform 404, anything else 409 invalid_status, and the report stays open with no audit row
    $state === 'draft' ? $response->assertNotFound() : $response->assertStatus(409)->assertJsonPath('code', 'invalid_status');
    expect($product->fresh()->status->value)->toBe($state === 'changes_requested' ? 'changes_requested' : $product->status->value);
    expect(Report::query()->where('status', 'open')->count())->toBe(1)->and(AuditLog::query()->count())->toBe(0);
})->with(['draft', 'pending', 'approved', 'scheduled', 'rejected', 'changes_requested']);

it('closes the reports of a product that is already taken down as resolved and audits only the closing', function () {
    // Given an admin and a taken-down product with an open report
    Event::fake([ProductTakenDown::class]);
    signedInStaff(Role::Admin);
    $product = rrReportedProduct(1, Product::factory()->takenDown()->create());

    // When the admin takes it down again through the report
    $response = rrPost('product', $product->id, ['action' => 'take_down', 'note' => 'Again']);

    // Then the product is unchanged, the report is resolved, and there is no second product.taken_down row or event
    $response->assertOk()->assertJsonPath('data.closed', 1)->assertJsonPath('data.target_changed', false);
    expect($product->fresh()->takedown_reason)->toBe('Test reason')->and(Report::query()->sole()->status)->toBe(ReportStatus::Resolved);
    expect(rrAudit())->toBe(['report.resolved']);
    Event::assertNotDispatched(ProductTakenDown::class);
});

it('dismisses a report of either type: the target is untouched, the reports are dismissed, only report.dismissed is audited', function (string $type, Role $role) {
    // Given a staff member and a reported target
    $staff = signedInStaff($role);
    $target = $type === 'comment' ? rrReportedComment(2) : rrReportedProduct(2);
    $reporter = Report::query()->first()->reporter;

    // When staff dismiss it (the note is optional)
    $response = rrPost($type, $target->id, ['action' => 'dismiss']);

    // Then the target is untouched, both reports are dismissed with the handler, and one audit row exists
    $response->assertOk()->assertExactJson(['data' => ['closed' => 2, 'action' => 'dismiss', 'type' => $type, 'target_id' => $target->id, 'target_changed' => false]]);
    $fresh = $target->fresh();
    $type === 'comment' ? expect($fresh->removed_at)->toBeNull()->and($fresh->deleted_at)->toBeNull() : expect($fresh->status)->toBe(ProductStatus::Live);
    expect(Report::query()->where('status', 'dismissed')->where('handled_by', $staff->id)->count())->toBe(2);
    expect(rrAudit())->toBe(['report.dismissed']);
    expect(AuditLog::query()->sole()->meta)->toEqual(['type' => $type, 'reports' => 2, 'action' => 'dismiss', 'note' => null]);

    // And the dismissed reporter cannot report the same target again (409 already_reviewed)
    Sanctum::actingAs($reporter);
    test()->postJson("/api/v1/{$type}s/{$target->id}/report", ['reason' => 'spam'])->assertStatus(409)->assertJsonPath('code', 'already_reviewed');
})->with([['comment', Role::Reviewer], ['comment', Role::Admin], ['product', Role::Reviewer], ['product', Role::Admin]]);

it('refuses every other role and sign-in state before anything else', function () {
    // Given a reported comment, and a member, a guest, a suspended reviewer and an unverified reviewer
    $comment = rrReportedComment(1);
    $payload = ['action' => 'dismiss'];

    // When each calls resolve
    // Then a member gets 403 for the existing and for an unknown id and for a nonsense body, identical
    Sanctum::actingAs(User::factory()->create());
    $bodies = [];
    foreach ([[$comment->id, $payload], ['01ARZ3NDEKTSV4RRFFQ69G5FAV', $payload], [$comment->id, ['action' => ['x']]], ['not-an-id', []]] as [$id, $body]) {
        $response = rrPost('comment', $id, $body)->assertForbidden();
        $bodies[] = $response->json('message');
    }
    expect(array_unique($bodies))->toHaveCount(1);
    // And a bad type is a 403 too for a member
    rrPost('nonsense', $comment->id, $payload)->assertForbidden();
    // And a guest is 401, a suspended reviewer 403 account_unavailable, an unverified one 403 email_unverified
    $this->app['auth']->forgetGuards();
    rrPost('comment', $comment->id, $payload)->assertUnauthorized();
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->suspended()->create());
    rrPost('comment', $comment->id, $payload)->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->unverified()->create());
    rrPost('comment', $comment->id, $payload)->assertForbidden()->assertJsonPath('code', 'email_unverified');
    expect(Report::query()->where('status', 'open')->count())->toBe(1)->and(AuditLog::query()->count())->toBe(0);
});

it('lets a staff member see the plain 404 for a bad type or id, and the action refuses a stale actor', function () {
    // Given a reviewer
    signedInStaff(Role::Reviewer);

    // When they resolve an unknown type, a non-ULID id, an unknown ULID, a draft product
    // Then every case is the uniform 404
    rrPost('thing', '01ARZ3NDEKTSV4RRFFQ69G5FAV', ['action' => 'dismiss'])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    rrPost('comment', 'not-a-ulid', ['action' => 'dismiss'])->assertNotFound();
    rrPost('comment', '01ARZ3NDEKTSV4RRFFQ69G5FAV', ['action' => 'dismiss'])->assertNotFound();
    rrPost('product', '01ARZ3NDEKTSV4RRFFQ69G5FAV', ['action' => 'dismiss'])->assertNotFound();
    rrPost('product', Product::factory()->create()->id, ['action' => 'dismiss'])->assertNotFound();

    // And an actor who lost the role (or was suspended) between the middleware and the action is refused by the action
    $comment = rrReportedComment(1);
    $stale = User::factory()->role(Role::Reviewer)->create();
    $stale->forceFill(['role' => Role::Member])->save();
    $staleModel = (clone $stale)->forceFill(['role' => Role::Reviewer]);
    expect(fn () => app(ResolveReport::class)->handle($staleModel, 'comment', $comment->id, ReportAction::Dismiss, null))->toThrow(AuthorizationException::class);
    $suspended = User::factory()->role(Role::Reviewer)->suspended()->create();
    expect(fn () => app(ResolveReport::class)->handle($suspended, 'comment', $comment->id, ReportAction::Dismiss, null))->toThrow(AuthorizationException::class);
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    expect(fn () => app(ResolveReport::class)->handle($reviewer, 'comment', $comment->id, ReportAction::TakeDown, 'x'))->toThrow(InvalidArgumentException::class);
    expect(Report::query()->where('status', 'open')->count())->toBe(1);
});

it('validates the body: action and note shapes, the wrong action for the type, the required note', function (string $type, mixed $payload) {
    // Given a reviewer and a reported target of the type
    signedInStaff(Role::Admin);
    $target = $type === 'comment' ? rrReportedComment(1) : rrReportedProduct(1);

    // When the body is bad
    $response = rrPost($type, $target->id, $payload);

    // Then 422 (never 500) and nothing changed
    $response->assertUnprocessable();
    expect(Report::query()->where('status', 'open')->count())->toBe(1)->and(AuditLog::query()->count())->toBe(0);
})->with([
    'no body, comment' => ['comment', []],
    'action null' => ['comment', ['action' => null]],
    'action empty' => ['comment', ['action' => '']],
    'action number' => ['comment', ['action' => 5]],
    'action bool' => ['comment', ['action' => true]],
    'action array' => ['comment', ['action' => ['dismiss']]],
    'action object' => ['comment', ['action' => ['a' => 'dismiss']]],
    'action unknown' => ['comment', ['action' => 'ban']],
    'remove_comment without note' => ['comment', ['action' => 'remove_comment']],
    'remove_comment blank note' => ['comment', ['action' => 'remove_comment', 'note' => '   ']],
    'remove_comment bidi-only note' => ['comment', ['action' => 'remove_comment', 'note' => "\u{202E}\u{200B}"]],
    'remove_comment null note' => ['comment', ['action' => 'remove_comment', 'note' => null]],
    'remove_comment array note' => ['comment', ['action' => 'remove_comment', 'note' => ['x']]],
    'remove_comment object note' => ['comment', ['action' => 'remove_comment', 'note' => ['a' => 'b']]],
    'remove_comment number note' => ['comment', ['action' => 'remove_comment', 'note' => 5]],
    'remove_comment note 501 chars' => ['comment', ['action' => 'remove_comment', 'note' => str_repeat('a', 501)]],
    'dismiss note array' => ['comment', ['action' => 'dismiss', 'note' => ['x']]],
    'dismiss note 501 chars' => ['comment', ['action' => 'dismiss', 'note' => str_repeat('a', 501)]],
    'take_down on a comment' => ['comment', ['action' => 'take_down', 'note' => 'Scam']],
    'remove_comment on a product' => ['product', ['action' => 'remove_comment', 'note' => 'Spam']],
    'take_down without note' => ['product', ['action' => 'take_down']],
    'take_down array note' => ['product', ['action' => 'take_down', 'note' => ['x']]],
]);

it('rejects an invalid UTF-8 note and strips control characters from a good one', function () {
    // Given an admin and reported comments
    signedInStaff(Role::Admin);
    $comment = rrReportedComment(1);

    // When the note has invalid UTF-8, and then control characters around real words
    $bad = rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => "bad \xB1\x31 text"]);
    $good = rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => "  Spam\x00 with\x07 links  "]);

    // Then the first is 422 and the second works with the cleaned reason; a 500-char note is accepted
    $bad->assertUnprocessable();
    $good->assertOk();
    expect($comment->fresh()->removed_reason)->toBe('Spam with links');
    $other = rrReportedComment(1);
    rrPost('comment', $other->id, ['action' => 'dismiss', 'note' => str_repeat('é', 500)])->assertOk();
});

it('answers a replay with closed 0, writes nothing more and moves no counter', function () {
    // Given a reported comment already removed by one resolve
    signedInStaff(Role::Reviewer);
    $comment = rrReportedComment(2);
    rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => 'spam'])->assertOk();
    $audit = AuditLog::query()->count();
    $before = [productCommentCounters($comment->product_id), $comment->fresh()->updated_at->format('Y-m-d H:i:s.u')];

    // When the same request is replayed
    $replay = rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => 'spam']);

    // Then it is 200 with closed 0 and nothing changed anywhere
    $replay->assertOk()->assertJsonPath('data.closed', 0)->assertJsonPath('data.target_changed', false);
    expect(AuditLog::query()->count())->toBe($audit)->and([productCommentCounters($comment->product_id), $comment->fresh()->updated_at->format('Y-m-d H:i:s.u')])->toBe($before);
});

it('removes nothing that nobody reported, whatever staff ask', function () {
    // Given an unreported comment and an unreported product
    signedInStaff(Role::Admin);
    $comment = app(CreateComment::class)->handle(User::factory()->create(), commentable()->id, 'Nobody reported this comment', null);
    $product = commentable();

    // When staff try to remove, take down, or dismiss them
    $results = [
        rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => 'because']),
        rrPost('product', $product->id, ['action' => 'take_down', 'note' => 'because']),
        rrPost('comment', $comment->id, ['action' => 'dismiss']),
    ];

    // Then each is 200 with closed 0 and the targets and counters are untouched, with no audit row
    foreach ($results as $response) {
        $response->assertOk()->assertJsonPath('data.closed', 0)->assertJsonPath('data.target_changed', false);
    }
    expect($comment->fresh()->removed_at)->toBeNull()->and($product->fresh()->status)->toBe(ProductStatus::Live)->and(AuditLog::query()->count())->toBe(0);
    expect(productCommentCounters($comment->product_id))->toBe([1, 1]);
});

it('closes the reports of a comment that was removed or deleted meanwhile as resolved, applying nothing', function (string $how) {
    // Given a reported comment that is already removed by staff, deleted by its author, or whose author deleted the account (counters kept consistent)
    signedInStaff(Role::Reviewer);
    $comment = rrReportedComment(2);
    $author = User::query()->findOrFail($comment->user_id);
    $counters = match ($how) {
        'removed' => (function () use ($comment) {
            staffRemoveComment($comment, User::factory()->role(Role::Reviewer)->create()->id);

            return [0, 0];
        })(),
        'deleted' => (function () use ($comment, $author) {
            // A deleted comment with open reports (the rare race that CreateReport's locks make unlikely): put the reports back
            app(DeleteComment::class)->handle($author, $comment->id);
            Report::query()->where('reportable_id', $comment->id)->update(['status' => 'open', 'handled_by' => null, 'handled_at' => null, 'resolution_note' => null]);

            return [0, 0];
        })(),
    };
    $auditBefore = AuditLog::query()->count();

    // When staff remove it through the reports
    $response = rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => 'spam']);

    // Then the reports are resolved, the target did not change, counters did not move, and only report.resolved is audited
    $response->assertOk()->assertJsonPath('data.closed', 2)->assertJsonPath('data.target_changed', false);
    expect(Report::query()->where('status', 'resolved')->count())->toBe(2);
    expect(productCommentCounters($comment->product_id))->toBe($counters);
    expect(AuditLog::query()->count())->toBe($auditBefore + 1)->and(AuditLog::query()->latest('created_at')->first()->action)->toBe('report.resolved');
})->with(['removed', 'deleted']);

it('also closes the reports of reporters who are no longer eligible, so the target is judged once', function () {
    // Given a reported comment whose reports come from a suspended reporter and a deleted one
    signedInStaff(Role::Reviewer);
    $comment = rrReportedComment(0);
    Report::factory()->forComment($comment)->create(['reporter_id' => User::factory()->suspended()->create()->id]);
    Report::factory()->forComment($comment)->create(['reporter_id' => User::factory()->create(['deleted_at' => now()])->id]);

    // When staff remove the comment
    $response = rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => 'spam']);

    // Then both reports are closed
    $response->assertOk()->assertJsonPath('data.closed', 2);
    expect(Report::query()->where('status', 'open')->count())->toBe(0);
});

it('leaves a report that arrives after the locks open, and the next resolve closes it without touching the target again', function () {
    // Given a reported comment, and an audit hook that files one more open report while the first resolve is still running
    signedInStaff(Role::Reviewer);
    $comment = rrReportedComment(1);
    $late = null;
    AuditLog::creating(function () use ($comment, &$late) {
        $late ??= Report::factory()->forComment($comment)->create();
    });

    // When staff remove the comment
    rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => 'spam'])->assertOk()->assertJsonPath('data.closed', 1);
    AuditLog::flushEventListeners();

    // Then the late report is still open
    expect($late->fresh()->status)->toBe(ReportStatus::Open);

    // And the next resolve closes it as a no-op on the target (already removed)
    rrPost('comment', $comment->id, ['action' => 'remove_comment', 'note' => 'spam'])->assertOk()->assertJsonPath('data.closed', 1)->assertJsonPath('data.target_changed', false);
    expect($late->fresh()->status)->toBe(ReportStatus::Resolved)->and(productCommentCounters($comment->product_id))->toBe([0, 0]);
});

it('rolls everything back when the audit write fails: nothing removed, counters intact, reports open, no audit rows', function (string $type) {
    // Given a reported target and an audit insert that fails
    $admin = User::factory()->role(Role::Admin)->create();
    $target = $type === 'comment' ? rrReportedComment(2) : rrReportedProduct(2);
    AuditLog::creating(function () {
        throw new RuntimeException('audit down');
    });
    $action = $type === 'comment' ? ReportAction::RemoveComment : ReportAction::TakeDown;

    // When the resolve runs
    $run = fn () => app(ResolveReport::class)->handle($admin, $type, $target->id, $action, 'a reason');

    // Then it throws and nothing changed
    expect($run)->toThrow(RuntimeException::class);
    expect(Report::query()->where('status', 'open')->count())->toBe(2)->and(AuditLog::query()->count())->toBe(0);
    if ($type === 'comment') {
        expect($target->fresh()->removed_at)->toBeNull()->and(productCommentCounters($target->product_id))->toBe([1, 1]);
    } else {
        expect($target->fresh()->status)->toBe(ProductStatus::Live);
    }
})->with(['comment', 'product']);

it('takes the product row first, then the parent and the comment, then the open reports, and never locks a user row exclusively', function () {
    // Given a reported reply
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $top = rrReportedComment(0);
    $reply = rrReportedComment(2, $top);
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') || str_contains($sql, 'for share') || str_contains($sql, 'for key share')) {
            $log[] = $sql;
        }
    });

    // When the reply is removed
    app(ResolveReport::class)->handle($reviewer, 'comment', $reply->id, ReportAction::RemoveComment, 'spam');

    // Then the first lock is the product, then two comment rows (the parent, then the reply), then the reports in id order, and no users lock
    expect($log)->toHaveCount(4);
    expect($log[0])->toContain('from "products"')->toContain('for update');
    expect($log[1])->toContain('from "comments"')->toContain('for update');
    expect($log[2])->toContain('from "comments"')->toContain('for update');
    expect($log[3])->toContain('from "reports"')->toContain('order by "id" asc')->toContain('for update');
    expect(collect($log)->filter(fn (string $sql) => str_contains($sql, 'from "users"'))->all())->toBe([]);
});

it('nests the take-down inside the resolve transaction (a savepoint level deeper), not as a second transaction', function () {
    // Given an admin, a reported live product, and a recorder of every transaction that begins and the level it begins at
    $admin = User::factory()->role(Role::Admin)->create();
    $product = rrReportedProduct(1);
    $levels = [];
    Event::listen(TransactionBeginning::class, function ($event) use (&$levels) {
        $levels[] = $event->connection->transactionLevel();
    });

    // When the product is taken down through the report
    $base = DB::transactionLevel();
    app(ResolveReport::class)->handle($admin, 'product', $product->id, ReportAction::TakeDown, 'Scam');

    // Then two transactions began, one nested in the other (the resolve, then the take-down), and the level is back
    expect($levels)->toBe([$base + 1, $base + 2])->and(DB::transactionLevel())->toBe($base);
});

it('does not apply a taken-down product\'s state changes when the product is in the wrong status', function () {
    // Given an admin and a pending product with a report
    $admin = User::factory()->role(Role::Admin)->create();
    $product = rrReportedProduct(1, Product::factory()->pending()->create());

    // When take_down meets it directly through the action
    // Then ProductRuleViolation invalid_status is thrown and the report stays open
    expect(fn () => app(ResolveReport::class)->handle($admin, 'product', $product->id, ReportAction::TakeDown, 'x'))->toThrow(ProductRuleViolation::class);
    expect(Report::query()->where('status', 'open')->count())->toBe(1);
    expect(fn () => app(ResolveReport::class)->handle($admin, 'comment', '01ARZ3NDEKTSV4RRFFQ69G5FAV', ReportAction::Dismiss, null))->toThrow(ModelNotFoundException::class);
});

it('refuses an actor who is suspended or demoted after the first role read but before anything is written', function (string $change, string $type) {
    // Given a staff member and a reported target, and a hook that changes the actor the moment the report rows are locked
    $actor = User::factory()->role($change === 'demoted-admin' ? Role::Admin : Role::Reviewer)->create();
    $target = $type === 'comment' ? rrReportedComment(1) : rrReportedProduct(1);
    $fired = false;
    DB::beforeExecuting(function (string $sql) use (&$fired, $actor, $change) {
        if (! $fired && str_contains(strtolower($sql), 'from "reports"') && str_contains(strtolower($sql), 'for update')) {
            $fired = true;
            DB::table('users')->where('id', $actor->id)->update($change === 'suspended' ? ['suspended_at' => now()] : ['role' => 'member']);
        }
    });
    $action = $type === 'comment' ? ReportAction::RemoveComment : ReportAction::TakeDown;

    // When the resolve runs
    $run = fn () => app(ResolveReport::class)->handle($actor, $type, $target->id, $action, 'a reason');

    // Then it is refused and nothing was written
    expect($run)->toThrow(AuthorizationException::class);
    expect($fired)->toBeTrue()->and(Report::query()->where('status', 'open')->count())->toBe(1)->and(AuditLog::query()->count())->toBe(0);
    $type === 'comment' ? expect($target->fresh()->removed_at)->toBeNull() : expect($target->fresh()->status)->toBe(ProductStatus::Live);
})->with([['suspended', 'comment'], ['demoted', 'comment'], ['demoted-admin', 'product']]);

it('answers a staff member who sends an invalid body for an unknown id: bad type or non-ULID is 404, an unknown ULID is 422 (validation first), a member 403', function () {
    // Given an invalid body
    $bad = ['action' => ['x'], 'note' => ['y']];

    // When a member, then a reviewer, post it
    Sanctum::actingAs(User::factory()->create());
    $member = rrPost('comment', '01ARZ3NDEKTSV4RRFFQ69G5FAV', $bad);
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());
    $badType = rrPost('thing', '01ARZ3NDEKTSV4RRFFQ69G5FAV', $bad);
    $badId = rrPost('comment', 'not-a-ulid', $bad);
    $unknown = rrPost('comment', '01ARZ3NDEKTSV4RRFFQ69G5FAV', $bad);

    // Then the order is 403, then 404 for a malformed route, then 422 (an unknown but well-formed id is only found by the action, after validation)
    $member->assertForbidden();
    $badType->assertNotFound();
    $badId->assertNotFound();
    $unknown->assertUnprocessable();
});
