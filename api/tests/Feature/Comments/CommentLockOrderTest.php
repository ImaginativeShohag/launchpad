<?php

use App\Actions\Comment\CreateComment;
use App\Actions\Comment\DeleteComment;
use App\Actions\Comment\EditComment;
use App\Events\CommentCreated;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../../Support/CommentFixtures.php';

/** The locking and users-touching statements of a callback, in order, lower-cased. */
function commentRecordLockStatements(Closure $callback): array
{
    // The producer listener (sub-project 5) runs after the action's own transaction and takes its own `users ... FOR SHARE`;
    // these tests are about the action's lock order, so the event it raises is not delivered to it.
    Event::fake([CommentCreated::class]);
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') || str_contains($sql, 'for share') || str_contains($sql, 'for key share') || str_contains($sql, '"users"')) {
            $log[] = $sql;
        }
    });
    $callback();

    return $log;
}

function commentLockTarget(string $sql): string
{
    foreach (['"products"', '"comments"', '"reports"', '"users"'] as $table) {
        if (str_contains($sql, 'from '.$table) || str_contains($sql, 'update '.$table)) {
            return trim($table, '"');
        }
    }

    return 'other';
}

it('locks the product first, then the top-level comment, then the answered reply, then the actor FOR SHARE (create)', function () {
    // Given a thread with a reply by another member, and an author
    $author = User::factory()->create();
    $product = commentable();
    $top = Comment::factory()->create(['product_id' => $product->id, 'replies_count' => 1]);
    $reply = Comment::factory()->replyTo($top)->create();

    // When the author answers the reply
    $log = commentRecordLockStatements(fn () => app(CreateComment::class)->handle($author, $product->id, 'Good point', $reply->id));

    // Then the first statement touching users is not before the product lock, the order is product, top, reply, actor share
    $locks = array_values(array_filter($log, fn (string $sql) => str_contains($sql, 'for update') || str_contains($sql, 'for share')));
    expect(commentLockTarget($locks[0]))->toBe('products')->and($locks[0])->toContain('for update');
    expect(commentLockTarget($log[0]))->toBe('products');
    $sequence = array_map(fn (string $sql) => commentLockTarget($sql).(str_contains($sql, 'for share') ? ':share' : ':update'), $locks);
    expect($sequence)->toBe(['products:update', 'comments:update', 'comments:update', 'users:share']);
    expect(collect($log)->contains(fn (string $sql) => commentLockTarget($sql) === 'users' && str_contains($sql, 'for update')))->toBeFalse();
});

it('locks the product then the actor for a top-level create', function () {
    // Given an author and a product
    $author = User::factory()->create();
    $product = commentable();

    // When they comment
    $log = commentRecordLockStatements(fn () => app(CreateComment::class)->handle($author, $product->id, 'Hello there', null));

    // Then the product comes first and the actor is shared-locked after it
    $locks = array_values(array_filter($log, fn (string $sql) => str_contains($sql, 'for update') || str_contains($sql, 'for share')));
    expect(array_map(fn (string $sql) => commentLockTarget($sql).(str_contains($sql, 'for share') ? ':share' : ':update'), $locks))->toBe(['products:update', 'users:share']);
    expect(commentLockTarget($log[0]))->toBe('products');
});

it('locks product, parent, comment, then the actor for an edit of a reply and a delete of a reply', function (string $verb) {
    // Given the author's reply
    $author = User::factory()->create();
    $product = commentable();
    $top = Comment::factory()->create(['product_id' => $product->id, 'replies_count' => 1]);
    $reply = Comment::factory()->replyTo($top)->create(['user_id' => $author->id, 'body' => 'Mine']);
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 2]);

    // When it is edited or deleted
    $log = commentRecordLockStatements(fn () => $verb === 'edit'
        ? app(EditComment::class)->handle($author, $reply->id, 'Edited')
        : app(DeleteComment::class)->handle($author, $reply->id));

    // Then the product row is locked before anything else touches users, then the parent, the reply, and the actor shared
    $locks = array_values(array_filter($log, fn (string $sql) => str_contains($sql, 'for update') || str_contains($sql, 'for share')));
    // (a delete also locks the comment's open report rows after the comment rows and before the actor)
    expect(array_map(fn (string $sql) => commentLockTarget($sql).(str_contains($sql, 'for share') ? ':share' : ':update'), $locks))
        ->toBe($verb === 'delete'
            ? ['products:update', 'comments:update', 'comments:update', 'reports:update', 'users:share']
            : ['products:update', 'comments:update', 'comments:update', 'users:share']);
    $firstUsers = collect($log)->search(fn (string $sql) => commentLockTarget($sql) === 'users');
    $productLock = collect($log)->search(fn (string $sql) => commentLockTarget($sql) === 'products' && str_contains($sql, 'for update'));
    expect($productLock)->toBeLessThan($firstUsers);
    expect(collect($log)->contains(fn (string $sql) => commentLockTarget($sql) === 'users' && str_contains($sql, 'for update')))->toBeFalse();
})->with(['edit', 'delete']);

it('locks the author and the answered account together FOR SHARE in id order, before the insert', function (bool $answeredIsLower) {
    // Given a reply by another member, with an id sorting below or above the author's
    $a = User::factory()->create();
    $b = User::factory()->create();
    [$lower, $higher] = $a->id < $b->id ? [$a, $b] : [$b, $a];
    [$author, $answered] = $answeredIsLower ? [$higher, $lower] : [$lower, $higher];
    $product = commentable();
    $top = Comment::factory()->create(['product_id' => $product->id, 'replies_count' => 1]);
    $reply = Comment::factory()->replyTo($top)->create(['user_id' => $answered->id]);
    Event::fake([CommentCreated::class]); // the producer listener's own share lock is not what this test is about
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings];
    });

    // When the author answers the reply
    app(CreateComment::class)->handle($author, $product->id, 'Good point', $reply->id);

    // Then one statement share-locks both users with ids ascending, and it comes before the comment insert
    $shares = array_values(array_filter($statements, fn (array $s) => str_contains($s['sql'], 'for share') && str_contains($s['sql'], 'from "users"')));
    expect($shares)->toHaveCount(1);
    expect($shares[0]['sql'])->toContain('order by "id"');
    expect($shares[0]['bindings'])->toBe([$lower->id, $higher->id]);
    $shareAt = array_search($shares[0], $statements, true);
    $insertAt = array_search(true, array_map(fn (array $s) => str_starts_with($s['sql'], 'insert into "comments"'), $statements), true);
    expect($shareAt)->toBeLessThan($insertAt);
})->with([true, false]);

it('keeps reply_to for a suspended answered author and drops it for an answered account deleted while we waited', function () {
    // Given a reply by a suspended member, and one by a member whose account is deleted after the plain read (simulated through the lock)
    $author = User::factory()->create();
    $product = commentable();
    $top = Comment::factory()->create(['product_id' => $product->id, 'replies_count' => 2]);
    $suspended = User::factory()->suspended()->create();
    $suspendedReply = Comment::factory()->replyTo($top)->create(['user_id' => $suspended->id]);
    $leaving = User::factory()->create();
    $leavingReply = Comment::factory()->replyTo($top)->create(['user_id' => $leaving->id]);

    // When the author answers the suspended member's reply
    $kept = app(CreateComment::class)->handle($author, $product->id, 'Hello suspended', $suspendedReply->id);

    // And an account deletion lands exactly after the thread is re-checked under its locks and before the users are share-locked
    $deleted = false;
    $checks = 0;
    DB::listen(function ($query) use ($leaving, $leavingReply, &$deleted, &$checks) {
        $sql = strtolower($query->sql);
        if (! $deleted && str_contains($sql, 'not exists') && in_array($leavingReply->id, $query->bindings, true)) {
            if (++$checks === 2) {
                $deleted = true;
                DB::table('users')->where('id', $leaving->id)->update(['deleted_at' => now()]);
            }
        }
    });
    $reply = app(CreateComment::class)->handle($author, $product->id, 'Hello leaving', $leavingReply->id);

    // Then the suspended member is remembered, the deleted one is not (the reply is still written)
    expect($kept->reply_to_user_id)->toBe($suspended->id);
    expect($reply->reply_to_user_id)->toBeNull();
    expect($deleted)->toBeTrue();
});
