<?php

use App\Actions\Comment\CreateComment;
use App\Events\CommentCreated;
use App\Models\Comment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

require_once __DIR__.'/../../Support/CommentFixtures.php';

it('dispatches CommentCreated once with the three ids, after the comment is committed', function () {
    // Given a member, a product and a faked dispatcher
    Event::fake([CommentCreated::class]);
    $member = signedInMember();
    $product = commentable();

    // When the member comments
    $response = commentPostRequest($product->id, ['body' => 'Hello there'])->assertCreated();

    // Then exactly one event carries the comment, product and author ids
    Event::assertDispatchedTimes(CommentCreated::class, 1);
    Event::assertDispatched(CommentCreated::class, fn (CommentCreated $e) => $e->commentId === $response->json('data.id') && $e->productId === $product->id && $e->userId === $member->id);
});

it('runs a real listener once per successful create and sees the committed row', function () {
    // Given a real listener registered by hand that reads the database
    $seen = [];
    Event::listen(CommentCreated::class, function (CommentCreated $event) use (&$seen) {
        $seen[] = [$event->commentId, DB::table('comments')->where('id', $event->commentId)->exists(), (int) DB::table('products')->where('id', $event->productId)->value('comments_count')];
    });
    signedInMember();
    $product = commentable();

    // When two comments are made
    $first = commentPostRequest($product->id, ['body' => 'First'])->assertCreated();
    $second = commentPostRequest($product->id, ['body' => 'Second'])->assertCreated();

    // Then the listener ran once per comment, saw the row and the updated counter
    expect($seen)->toBe([[$first->json('data.id'), true, 1], [$second->json('data.id'), true, 2]]);
});

it('never runs the listener for a refused request or a rolled-back transaction', function () {
    // Given a listener, a member and a product
    $calls = 0;
    Event::listen(CommentCreated::class, function () use (&$calls) {
        $calls++;
    });
    $member = signedInMember();
    $product = commentable();

    // When requests are refused (validation, hidden product, bad parent) and one create runs inside a transaction that rolls back
    commentPostRequest($product->id, ['body' => '   '])->assertUnprocessable();
    commentPostRequest('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['body' => 'Hello there'])->assertNotFound();
    commentPostRequest($product->id, ['body' => 'Hello there', 'parent_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->assertNotFound();
    try {
        DB::transaction(function () use ($member, $product) {
            app(CreateComment::class)->handle($member, $product->id, 'Rolled back', null);
            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    // Then the listener never ran
    expect($calls)->toBe(0)->and(Comment::query()->count())->toBe(0);
});

it('does not fail the request when a listener throws: 201, the row is committed, the failure is reported', function () {
    // Given a listener that always throws, and a recorder of reported exceptions
    Exceptions::fake();
    Event::listen(CommentCreated::class, function () {
        throw new RuntimeException('listener down');
    });
    signedInMember();
    $product = commentable();

    // When the member comments
    $response = commentPostRequest($product->id, ['body' => 'Hello there']);

    // Then the comment is saved and answered, and the failure was reported
    $response->assertCreated();
    expect(Comment::query()->count())->toBe(1)->and(productCommentCounters($product))->toBe([1, 1]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'listener down');
});

it('dispatches nothing on edit and delete', function () {
    // Given the author's comment and a faked dispatcher
    Event::fake([CommentCreated::class]);
    $author = signedInMember();
    $comment = Comment::factory()->create(['user_id' => $author->id, 'body' => 'Original text']);
    DB::table('products')->where('id', $comment->product_id)->update(['comments_count' => 1]);

    // When the author edits and deletes it
    $this->patchJson("/api/v1/comments/{$comment->id}", ['body' => 'Edited'])->assertOk();
    $this->deleteJson("/api/v1/comments/{$comment->id}")->assertOk();

    // Then no event fired
    Event::assertNotDispatched(CommentCreated::class);
});

it('has the documented shape: ids only, dispatched after commit', function () {
    // Given the event class
    $reflection = new ReflectionClass(CommentCreated::class);

    // When its constructor and interfaces are read
    $params = array_map(fn ($p) => $p->getName().':'.$p->getType(), $reflection->getConstructor()->getParameters());

    // Then it carries three string ids and waits for the commit
    expect($params)->toBe(['commentId:string', 'productId:string', 'userId:string']);
    expect($reflection->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue();
});
