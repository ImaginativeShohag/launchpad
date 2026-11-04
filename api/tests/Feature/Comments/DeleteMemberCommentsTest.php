<?php

use App\Actions\Comment\CreateComment;
use App\Actions\Comment\DeleteComment;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Jobs\DeleteMemberComments;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';

afterEach(fn () => Comment::flushEventListeners());

/** Marks the account deleted the way DeleteAccount's anonymisation does (the comments are left alone, as there). */
function memberCommentsLeave(User $member): User
{
    $member->forceFill(['deleted_at' => now(), 'name' => 'Deleted member'])->save();

    return $member;
}

function memberCommentsRun(User $member, int $chunk = DeleteMemberComments::CHUNK): void
{
    (new DeleteMemberComments($member->id))->handle(app(DeleteComment::class), $chunk);
}

it('declares a unique, retried queued job keyed on the user id', function () {
    // Given the job for a user
    $job = new DeleteMemberComments('01ARZ3NDEKTSV4RRFFQ69G5FAV');

    // When its contract is inspected
    // Then it is queued, unique per user for an hour, with three tries, two backoffs and a timeout below retry_after
    expect($job)->toBeInstanceOf(ShouldQueue::class)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')->and($job->tries)->toBe(3)->and($job->backoff())->toBe([10, 60]);
    expect($job->uniqueFor)->toBe(3600)->and($job->timeout)->toBe(60)->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
    expect(DeleteMemberComments::CHUNK)->toBe(100);
});

it('wipes a deleted member\'s comments and corrects the counters the deleted account left inflated', function () {
    // Given a product with a thread by live members and a deleted member's top-level comment and two replies, made through the real actions
    $product = commentable();
    [$live, $leaver] = User::factory()->count(2)->create()->all();
    $create = app(CreateComment::class);
    $liveTop = $create->handle($live, $product->id, 'A live top level comment', null);
    $leaverTop = $create->handle($leaver, $product->id, 'Personal words by the leaver', null);
    $leaverReply = $create->handle($leaver, $product->id, 'A reply by the leaver', $liveTop->id);
    $liveReplyToLeaver = $create->handle($live, $product->id, 'A live reply to the leaver', $leaverTop->id);
    expect(productCommentCounters($product))->toBe([4, 4]);
    memberCommentsLeave($leaver);

    // When the clean-up job runs
    memberCommentsRun($leaver);

    // Then the leaver's rows are wiped, the counters moved by exactly the leaver's counted rows, and everybody else's rows are untouched
    foreach ([$leaverTop, $leaverReply] as $comment) {
        $row = $comment->fresh();
        expect($row->body)->toBe('')->and($row->deleted_at)->not->toBeNull()->and($row->removed_at)->toBeNull();
    }
    expect(DB::table('products')->where('id', $product->id)->value('comments_count'))->toBe(2);
    expect(DB::table('comments')->where('id', $liveTop->id)->value('replies_count'))->toBe(0);
    // A deleted top-level keeps its replies_count: the live reply below it stays counted and visible
    expect(DB::table('comments')->where('id', $leaverTop->id)->value('replies_count'))->toBe(1);
    expect($liveTop->fresh()->body)->toBe('A live top level comment')->and($liveReplyToLeaver->fresh()->body)->toBe('A live reply to the leaver')->and($liveReplyToLeaver->fresh()->deleted_at)->toBeNull();
    assertCommentCountersConsistent();
});

it('closes the open reports of an erased comment as resolved by the system', function () {
    // Given a deleted member's comment with an open report and an unrelated reported comment
    $leaver = User::factory()->create();
    $mine = Comment::factory()->create(['user_id' => $leaver->id]);
    $other = Comment::factory()->create();
    DB::table('products')->whereIn('id', [$mine->product_id, $other->product_id])->update(['comments_count' => 1]);
    $report = Report::factory()->forComment($mine)->create();
    $unrelated = Report::factory()->forComment($other)->create();
    memberCommentsLeave($leaver);

    // When the job runs
    memberCommentsRun($leaver);

    // Then the report is resolved with no handler and the note account_deleted, and the unrelated one stays open
    $closed = $report->fresh();
    expect($closed->status->value)->toBe('resolved')->and($closed->handled_by)->toBeNull()->and($closed->handled_at)->not->toBeNull()->and($closed->resolution_note)->toBe('account_deleted');
    expect($unrelated->fresh()->status->value)->toBe('open');
});

it('wipes the body of a staff-removed comment without touching a counter and keeps the removal record', function () {
    // Given a deleted member's comment that staff removed (already uncounted)
    $leaver = User::factory()->create();
    $staff = User::factory()->role(Role::Reviewer)->create();
    $product = commentable();
    $comment = Comment::factory()->removed($staff, 'abusive words')->create(['product_id' => $product->id, 'user_id' => $leaver->id, 'body' => 'Evidence kept for staff']);
    memberCommentsLeave($leaver);

    // When the job runs
    memberCommentsRun($leaver);

    // Then the personal text is gone, the removal record is intact, and the counter did not move (it never counted this row)
    $row = $comment->fresh();
    expect($row->body)->toBe('')->and($row->deleted_at)->not->toBeNull()->and($row->removed_at)->not->toBeNull()
        ->and($row->removed_by)->toBe($staff->id)->and($row->removed_reason)->toBe('abusive words');
    expect(DB::table('products')->where('id', $product->id)->value('comments_count'))->toBe(0);
});

it('erases the comments on a taken-down product too', function () {
    // Given a deleted member's comment on a taken-down product
    $leaver = User::factory()->create();
    $product = commentable();
    app(CreateComment::class)->handle($leaver, $product->id, 'A comment before the take-down', null);
    $product->forceFill(['status' => ProductStatus::TakenDown, 'taken_down_at' => now(), 'taken_down_by' => User::factory()->role(Role::Admin)->create()->id, 'takedown_reason' => 'x'])->save();
    memberCommentsLeave($leaver);

    // When the job runs
    memberCommentsRun($leaver);

    // Then it is erased and the counter is corrected
    expect(DB::table('comments')->where('user_id', $leaver->id)->value('body'))->toBe('');
    expect(DB::table('products')->where('id', $product->id)->value('comments_count'))->toBe(0);
});

it('changes nothing on a second run and nothing for a live member', function () {
    // Given a deleted member's comment and a live member's comment
    $leaver = User::factory()->create();
    $live = User::factory()->create();
    $product = commentable();
    $create = app(CreateComment::class);
    $create->handle($leaver, $product->id, 'Leaver text', null);
    $liveComment = $create->handle($live, $product->id, 'Live text', null);
    memberCommentsLeave($leaver);
    memberCommentsRun($leaver);
    $after = [DB::table('comments')->orderBy('id')->get()->all(), productCommentCounters($product)];

    // When the job runs again, and once for the live member (counting its queries)
    memberCommentsRun($leaver);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    memberCommentsRun($live);
    $jobQueries = $queries;

    // Then nothing changed (including updated_at), and the live member's comment is intact
    expect([DB::table('comments')->orderBy('id')->get()->all(), productCommentCounters($product)])->toEqual($after);
    expect($liveComment->fresh()->body)->toBe('Live text')->and($liveComment->fresh()->deleted_at)->toBeNull();
    expect(app(DeleteComment::class)->forDeletedMember($liveComment->id))->toBeFalse();
    // And the job gave up after its one account check, without even listing the member's comments
    expect($jobQueries)->toHaveCount(1);
});

it('says whether forDeletedMember changed anything', function () {
    // Given a deleted member's counted comment
    $leaver = User::factory()->create();
    $comment = app(CreateComment::class)->handle($leaver, commentable()->id, 'Leaver text', null);
    memberCommentsLeave($leaver);

    // When the action runs twice, and with a non-ULID and an unknown id
    $delete = app(DeleteComment::class);
    $first = $delete->forDeletedMember($comment->id);
    $second = $delete->forDeletedMember($comment->id);

    // Then only the first run changed something
    expect($first)->toBeTrue()->and($second)->toBeFalse()->and($delete->forDeletedMember('nope'))->toBeFalse()->and($delete->forDeletedMember('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->toBeFalse();
});

it('keeps the counter invariant after a random mix of live and deleted members', function (int $seed) {
    // Given 4 members (two of them leave), 2 products and 60 seeded random creates and replies
    $state = $seed;
    $rand = function (int $min, int $max) use (&$state): int {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $min + ($state >> 8) % ($max - $min + 1);
    };
    $members = User::factory()->count(4)->create();
    $products = [commentable(), commentable()];
    $create = app(CreateComment::class);
    $made = [];
    foreach (range(1, 60) as $step) {
        $product = $products[$rand(0, 1)];
        $pool = array_values(array_filter($made, fn (Comment $c) => $c->product_id === $product->id));
        $made[] = $create->handle($members[$rand(0, 3)], $product->id, "Comment {$step}", $pool === [] || $rand(1, 3) === 1 ? null : $pool[$rand(0, count($pool) - 1)]->id);
    }

    // When two members delete their accounts and the jobs run
    foreach ([$members[0], $members[2]] as $leaver) {
        memberCommentsLeave($leaver);
        memberCommentsRun($leaver);
    }

    // Then every counter equals the rows that are still counted, and only the two members' rows were erased
    assertCommentCountersConsistent();
    expect(DB::table('comments')->whereIn('user_id', [$members[0]->id, $members[2]->id])->whereNull('deleted_at')->count())->toBe(0);
    expect(DB::table('comments')->whereIn('user_id', [$members[1]->id, $members[3]->id])->whereNotNull('deleted_at')->count())->toBe(0);
})->with([3, 11, 29]);

it('goes on past a failing comment and rethrows at the end', function () {
    // Given a deleted member with three comments and a hook that makes the update of the first one fail
    Exceptions::fake();
    $leaver = User::factory()->create();
    $product = commentable();
    $create = app(CreateComment::class);
    $comments = array_map(fn (int $i) => $create->handle($leaver, $product->id, "Comment {$i}", null), [1, 2, 3]);
    memberCommentsLeave($leaver);
    $bad = collect($comments)->sortBy('id')->first();
    Comment::updating(function (Comment $comment) use ($bad) {
        if ($comment->getKey() === $bad->id) {
            DB::statement('select 1/0');
        }
    });

    // When the job runs with a chunk of 2 (a loop that did not move its cursor past the failure would never end)
    $run = fn () => memberCommentsRun($leaver, 2);

    // Then it rethrows after finishing, the other two are erased, the failed one is not, and its counter effect rolled back
    expect($run)->toThrow(QueryException::class);
    expect(DB::table('comments')->whereNotNull('deleted_at')->count())->toBe(2)->and($bad->fresh()->deleted_at)->toBeNull();
    expect(DB::table('products')->where('id', $product->id)->value('comments_count'))->toBe(1);
    Exceptions::assertReportedCount(1);
});

it('pages through more comments than one chunk', function () {
    // Given a deleted member with five comments
    $leaver = User::factory()->create();
    $product = commentable();
    $create = app(CreateComment::class);
    foreach (range(1, 5) as $i) {
        $create->handle($leaver, $product->id, "Comment {$i}", null);
    }
    memberCommentsLeave($leaver);

    // When the job runs with a chunk of 2
    memberCommentsRun($leaver, 2);

    // Then all five are erased and the counter is zero
    expect(DB::table('comments')->where('user_id', $leaver->id)->whereNull('deleted_at')->count())->toBe(0);
    expect(DB::table('products')->where('id', $product->id)->value('comments_count'))->toBe(0);
});

it('takes the product row first, then the parent, then the comment, and no user row lock', function () {
    // Given a deleted member's reply under a live member's comment
    $leaver = User::factory()->create();
    $product = commentable();
    $top = app(CreateComment::class)->handle(User::factory()->create(), $product->id, 'Top', null);
    app(CreateComment::class)->handle($leaver, $product->id, 'Reply', $top->id);
    memberCommentsLeave($leaver);
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') || str_contains($sql, 'for share')) {
            $log[] = $sql;
        }
    });

    // When the job runs
    memberCommentsRun($leaver);

    // Then the first lock is the product, then two comment locks (the parent, the reply), then the open reports, and no users lock at all
    expect($log[0])->toContain('from "products"')->toContain('for update');
    expect($log[1])->toContain('from "comments"')->and($log[2])->toContain('from "comments"')->and($log[3])->toContain('from "reports"');
    expect(collect($log)->filter(fn (string $sql) => str_contains($sql, 'from "users"'))->all())->toBe([]);
});

it('closes a deleted reporter\'s open reports as dismissed by the system when no other eligible report is open, keeping their text in the history', function () {
    // Given a deleted reporter with an open report (with details) on a comment that nobody else reported
    $gone = User::factory()->create();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);
    $report = Report::factory()->forComment($comment)->create(['reporter_id' => $gone->id, 'details' => 'Words the reporter wrote']);
    memberCommentsLeave($gone);

    // When the job runs
    memberCommentsRun($gone);

    // Then the report is dismissed with no handler, a time and the note reporter_deleted, and the text is still in the staff history
    $row = $report->fresh();
    expect($row->status->value)->toBe('dismissed')->and($row->handled_by)->toBeNull()->and($row->handled_at)->not->toBeNull()->and($row->resolution_note)->toBe('reporter_deleted')->and($row->details)->toBe('Words the reporter wrote');
    Sanctum::actingAs(User::factory()->role(Role::Reviewer)->create());
    $history = test()->getJson('/api/v1/staff/reports/history')->assertOk();
    expect($history->json('data.0.details'))->toBe('Words the reporter wrote')->and($history->json('data.0.handled_by'))->toBeNull();
});

it('leaves the report open while another eligible reporter has an open report on the same target, and does not count an ineligible one', function () {
    // Given a target reported by a deleted reporter and by an eligible one, and another target reported by a deleted reporter and a suspended one
    $gone = User::factory()->create();
    $guarded = Comment::factory()->create(['product_id' => commentable()->id]);
    $open = Comment::factory()->create(['product_id' => commentable()->id]);
    $kept = Report::factory()->forComment($guarded)->create(['reporter_id' => $gone->id]);
    Report::factory()->forComment($guarded)->create();
    $closable = Report::factory()->forComment($open)->create(['reporter_id' => $gone->id]);
    Report::factory()->forComment($open)->create(['reporter_id' => User::factory()->suspended()->create()->id]);
    memberCommentsLeave($gone);

    // When the job runs
    memberCommentsRun($gone);

    // Then the first stays open (a resolve of the target closes it with its siblings), the second is closed
    expect($kept->fresh()->status->value)->toBe('open')->and($closable->fresh()->status->value)->toBe('dismissed');
});

it('is idempotent for the report step, and a live member\'s reports are never touched', function () {
    // Given a deleted and a live reporter, each with an open report
    $gone = User::factory()->create();
    $live = User::factory()->create();
    $mine = Report::factory()->forComment(Comment::factory()->create(['product_id' => commentable()->id]))->create(['reporter_id' => $gone->id]);
    $theirs = Report::factory()->forComment(Comment::factory()->create(['product_id' => commentable()->id]))->create(['reporter_id' => $live->id]);
    memberCommentsLeave($gone);
    memberCommentsRun($gone);
    $first = $mine->fresh();

    // When the job runs again for both
    memberCommentsRun($gone);
    memberCommentsRun($live);

    // Then nothing changed
    expect($mine->fresh()->handled_at->eq($first->handled_at))->toBeTrue()->and($theirs->fresh()->status->value)->toBe('open');
});

it('takes no user or product row lock in the report step', function () {
    // Given a deleted reporter with an open report and no comments
    $gone = User::factory()->create();
    Report::factory()->forComment(Comment::factory()->create(['product_id' => commentable()->id]))->create(['reporter_id' => $gone->id]);
    memberCommentsLeave($gone);
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') || str_contains($sql, 'for share')) {
            $log[] = $sql;
        }
    });

    // When the job runs
    memberCommentsRun($gone);

    // Then the only lock taken is on the report rows, ordered by id
    expect($log)->toHaveCount(1);
    expect($log[0])->toContain('from "reports"')->toContain('order by "id" asc');
});

it('stops after its time budget, hands the rest to a fresh job through the bus (past the unique lock) and does not throw', function () {
    // Given a deleted member with three comments, an open report, a fake queue, and the unique lock held as a running worker would
    Queue::fake();
    $leaver = User::factory()->create();
    $product = commentable();
    $create = app(CreateComment::class);
    foreach ([1, 2, 3] as $i) {
        $create->handle($leaver, $product->id, "Comment {$i}", null);
    }
    $report = Report::factory()->forComment(Comment::factory()->create(['product_id' => $product->id]))->create(['reporter_id' => $leaver->id]);
    memberCommentsLeave($leaver);
    DeleteMemberComments::dispatch($leaver->id);
    DeleteMemberComments::dispatch($leaver->id);
    Queue::assertPushed(DeleteMemberComments::class, 1);
    $job = new DeleteMemberComments($leaver->id);
    $job->budget = -1.0;

    // When the job runs with a budget that is already used up
    $job->handle(app(DeleteComment::class));

    // Then it erased one comment, queued exactly one more job even though the unique lock is held, did not throw, and left the report step to that job
    expect(DB::table('comments')->where('user_id', $leaver->id)->whereNotNull('deleted_at')->count())->toBe(1);
    Queue::assertPushed(DeleteMemberComments::class, 2);
    expect($report->fresh()->status->value)->toBe('open');
});
