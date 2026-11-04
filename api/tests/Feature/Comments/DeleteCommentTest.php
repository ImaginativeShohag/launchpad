<?php

use App\Actions\Comment\DeleteComment;
use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

function commentDeleteRequest(string $id)
{
    return test()->deleteJson("/api/v1/comments/{$id}");
}

/** A product with a top-level comment by `$author` (counted) and the counters set to match. */
function commentThreadOf(User $author, int $replies = 0): array
{
    $product = commentable();
    $top = Comment::factory()->create(['product_id' => $product->id, 'user_id' => $author->id, 'body' => 'Top comment', 'replies_count' => $replies]);
    $others = [];
    for ($i = 1; $i <= $replies; $i++) {
        $others[] = Comment::factory()->replyTo($top)->create(['body' => "Reply {$i}"]);
    }
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 1 + $replies]);

    return [$product, $top, $others];
}

it('deletes a top-level comment without replies: placeholder answer, wiped body, counter -1', function () {
    // Given the author's only comment
    $author = signedInMember();
    [$product, $top] = commentThreadOf($author);

    // When the author deletes it
    $response = commentDeleteRequest($top->id);

    // Then the answer is the placeholder and the row is wiped but kept
    $response->assertOk()->assertJsonPath('data.state', 'deleted')->assertJsonPath('data.body', null)->assertJsonPath('data.author', null)
        ->assertJsonPath('data.can_edit', false)->assertJsonPath('data.can_delete', false);
    $row = $top->fresh();
    expect($row->body)->toBe('')->and($row->deleted_at)->not->toBeNull()->and($row->removed_at)->toBeNull();
    expect(productCommentCounters($product))->toBe([0, 0]);
    assertCommentCountersConsistent();
});

it('deletes a reply: the parent loses a reply and the product loses a comment', function () {
    // Given the author's reply to somebody's comment
    $author = signedInMember();
    $product = commentable();
    $top = Comment::factory()->create(['product_id' => $product->id, 'replies_count' => 1]);
    $reply = Comment::factory()->replyTo($top)->create(['user_id' => $author->id]);
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 2]);

    // When the author deletes the reply
    commentDeleteRequest($reply->id)->assertOk()->assertJsonPath('data.state', 'deleted')->assertJsonPath('data.parent_id', $top->id);

    // Then both counters dropped by one
    expect($top->fresh()->replies_count)->toBe(0)->and(productCommentCounters($product))->toBe([1, 1]);
    assertCommentCountersConsistent();
});

it('keeps the replies of a deleted top-level comment: its replies_count and their visibility are untouched', function () {
    // Given the author's top-level comment with two replies by others
    $author = signedInMember();
    [$product, $top, $replies] = commentThreadOf($author, 2);

    // When the author deletes the top-level comment
    commentDeleteRequest($top->id)->assertOk();

    // Then only the parent left the counters: its replies stay counted and attached
    expect($top->fresh()->replies_count)->toBe(2)->and(productCommentCounters($product))->toBe([2, 2]);
    expect(Comment::query()->where('parent_id', $top->id)->counted()->count())->toBe(2);
    assertCommentCountersConsistent();
});

it('is idempotent: a second delete answers 200 and changes nothing', function () {
    // Given a comment deleted once
    $author = signedInMember();
    [$product, $top] = commentThreadOf($author);
    commentDeleteRequest($top->id)->assertOk();
    $deletedAt = $top->fresh()->deleted_at->toIso8601String();
    $this->travel(5)->minutes();

    // When it is deleted again
    $second = commentDeleteRequest($top->id);

    // Then it is 200, and neither the instant nor the counters moved
    $second->assertOk()->assertJsonPath('data.state', 'deleted');
    expect($top->fresh()->deleted_at->toIso8601String())->toBe($deletedAt)->and(productCommentCounters($product))->toBe([0, 0]);
});

it('answers a staff-removed comment with 404 (it is "missing" to its author)', function () {
    // Given the author's comment removed by staff
    $author = signedInMember();
    $product = commentable();
    $removed = Comment::factory()->removed()->create(['product_id' => $product->id, 'user_id' => $author->id]);

    // When the author deletes it
    // Then it is the uniform 404 and the removal record is intact
    commentDeleteRequest($removed->id)->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect($removed->fresh()->deleted_at)->toBeNull()->and($removed->fresh()->removed_at)->not->toBeNull();
});

it('answers other people\'s comments and bad ids with the identical 404', function (string $case) {
    // Given a comment by someone else
    $author = User::factory()->create();
    [$product, $top] = commentThreadOf($author);
    match ($case) {
        'staff' => signedInStaff(Role::Admin),
        'maker' => (function () use ($product) {
            Sanctum::actingAs($product->maker);
        })(),
        default => signedInMember(),
    };
    $id = match ($case) {
        'missing' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not a ulid' => 'nope', '200 chars' => str_repeat('a', 200), default => $top->id,
    };

    // When they delete it
    $response = commentDeleteRequest($id);

    // Then it is the uniform 404 and the comment is untouched
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect($top->fresh()->body)->toBe('Top comment')->and(productCommentCounters($product))->toBe([1, 1]);
})->with(['stranger', 'staff', 'maker', 'missing', 'not a ulid', '200 chars']);

it('answers a comment on a hidden product with 404 and changes nothing', function (string $case) {
    // Given the author's comment on a product that is no longer public
    $author = signedInMember();
    $product = match ($case) {
        'taken down' => Product::factory()->takenDown()->create(),
        'soft deleted' => commentable(['deleted_at' => now()]),
        'deleted maker' => commentable(['maker_id' => User::factory()->create(['deleted_at' => now()])->id]),
    };
    $comment = Comment::factory()->create(['product_id' => $product->id, 'user_id' => $author->id]);

    // When the author deletes it
    // Then it is the uniform 404
    commentDeleteRequest($comment->id)->assertNotFound();
    expect($comment->fresh()->deleted_at)->toBeNull();
})->with(['taken down', 'soft deleted', 'deleted maker']);

it('no longer lets the author edit a deleted comment, nor anyone answer a deleted top-level comment', function () {
    // Given a deleted top-level comment
    $author = signedInMember();
    [$product, $top] = commentThreadOf($author);
    commentDeleteRequest($top->id)->assertOk();

    // When the author edits it and a member answers it
    $edit = $this->patchJson("/api/v1/comments/{$top->id}", ['body' => 'Back again']);
    $answer = commentPostRequest($product->id, ['body' => 'Replying to nothing', 'parent_id' => $top->id]);

    // Then both are 404
    $edit->assertNotFound();
    $answer->assertNotFound();
    expect($top->fresh()->body)->toBe('');
});

it('applies the route guards and refuses a stale author', function () {
    // Given a comment
    $comment = Comment::factory()->create();

    // When a guest, an unverified and a suspended member delete it
    $guest = commentDeleteRequest($comment->id);
    $this->app['auth']->forgetGuards();
    signedInMember(['email_verified_at' => null]);
    $unverified = commentDeleteRequest($comment->id);
    $this->app['auth']->forgetGuards();
    signedInMember(['suspended_at' => now(), 'suspended_reason' => 'x']);
    $suspended = commentDeleteRequest($comment->id);

    // Then the guards answer
    $guest->assertUnauthorized();
    $unverified->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $suspended->assertForbidden()->assertJsonPath('code', 'account_unavailable');

    // And a stale model (suspended after authorisation) is refused by the action under lock
    $author = User::factory()->create();
    [$product, $top] = commentThreadOf($author);
    DB::table('users')->where('id', $author->id)->update(['suspended_at' => now()]);
    expect(fn () => app(DeleteComment::class)->handle($author, $top->id))->toThrow(AuthorizationException::class);
    expect($top->fresh()->deleted_at)->toBeNull()->and(productCommentCounters($product))->toBe([1, 1]);
});

it('keeps the removal reason out of the placeholder and ignores a request body', function () {
    // Given the author's comment
    $author = signedInMember();
    [$product, $top] = commentThreadOf($author);

    // When it is deleted with a stray body
    $response = $this->deleteJson("/api/v1/comments/{$top->id}", ['body' => ['x'], 'anything' => null]);

    // Then the answer is fine and shows no staff fields
    $response->assertOk();
    expect($response->getContent())->not->toContain('removed_by')->not->toContain('removed_reason');
});

it('closes the comment\'s open reports as resolved by the system, once, and leaves other comments\' reports open', function () {
    // Given the author's comment with two open reports and a dismissed one, and another comment with an open report
    $author = signedInMember();
    [$product, $top] = commentThreadOf($author);
    $other = Comment::factory()->create(['product_id' => $product->id]);
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $open = Report::factory()->forComment($top)->count(2)->create();
    $dismissed = Report::factory()->forComment($top)->dismissed($reviewer)->create();
    $untouched = Report::factory()->forComment($other)->create();
    $dismissedHandledAt = $dismissed->fresh()->handled_at->toIso8601String();

    // When the author deletes the comment
    commentDeleteRequest($top->id)->assertOk();

    // Then the open reports are resolved with no handler and the note deleted_by_author; the others are as they were
    foreach ($open as $report) {
        $row = $report->fresh();
        expect($row->status)->toBe(ReportStatus::Resolved)->and($row->handled_by)->toBeNull()->and($row->resolution_note)->toBe('deleted_by_author')->and($row->handled_at)->not->toBeNull();
    }
    expect($dismissed->fresh()->status)->toBe(ReportStatus::Dismissed)->and($dismissed->fresh()->handled_at->toIso8601String())->toBe($dismissedHandledAt);
    expect($untouched->fresh()->status)->toBe(ReportStatus::Open);

    // And a repeat delete changes nothing (the handled instants stay)
    $handledAt = $open[0]->fresh()->handled_at->toIso8601String();
    $this->travel(5)->minutes();
    commentDeleteRequest($top->id)->assertOk();
    expect($open[0]->fresh()->handled_at->toIso8601String())->toBe($handledAt);
});
