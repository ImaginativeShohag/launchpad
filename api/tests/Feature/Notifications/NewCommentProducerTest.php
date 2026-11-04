<?php

use App\Events\CommentCreated;
use App\Listeners\NotifyNewComment;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';
require_once __DIR__.'/../../Support/MailFixtures.php';

/** Posts a comment (or a reply) as $author through the real endpoint and returns its id. */
function producerComment(User $author, Product $product, ?string $parentId = null, string $body = 'A perfectly fine comment'): string
{
    Sanctum::actingAs($author);
    $response = commentPostRequest($product->id, array_filter(['body' => $body, 'parent_id' => $parentId]))->assertCreated();

    return (string) $response->json('data.id');
}

/** @return list<array{string, string}> [recipient id, kind] of every bell row, sorted */
function producerRecipients(): array
{
    $rows = DB::table('notifications')->get()->map(fn ($r) => [$r->user_id, json_decode($r->data, true)['params']['kind']])->all();
    usort($rows, fn ($a, $b) => $a <=> $b);

    return $rows;
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-21 10:10:00 UTC'));
    $this->maker = User::factory()->create();
    $this->product = Product::factory()->live()->create(['maker_id' => $this->maker->id, 'name' => 'Acme', 'slug' => 'acme']);
    $this->alice = User::factory()->create();
    $this->bob = User::factory()->create();
});

it('notifies the maker of a top-level comment, in the bell and by mail, without the comment text', function () {
    // Given a member who comments on the maker's product (real listeners)
    // When the comment is posted
    $id = producerComment($this->alice, $this->product, null, 'SECRET-BODY about the thing');

    // Then the maker has one bell row of kind comment and one mail, and the text is nowhere
    [$bell, $mail] = producerRows();
    expect($bell)->toHaveCount(1)->and($mail)->toHaveCount(1);
    expect($bell[0]['user_id'])->toBe($this->maker->id)->and($bell[0]['type'])->toBe('new_comment')->and($bell[0]['dedupe_key'])->toBe('comment:'.$id);
    expect(json_decode($bell[0]['data'], true))->toEqual([
        'message_key' => 'notifications.new_comment', 'product_id' => $this->product->id, 'comment_id' => $id,
        'params' => ['product_name' => 'Acme', 'kind' => 'comment'], 'href' => '/products/acme',
    ]);
    $bucket = now('UTC')->format('YmdH');
    expect($mail[0]['type'])->toBe('new_comment')->and($mail[0]['idempotency_key'])->toBe("new_comment:comment:{$this->product->id}:{$bucket}:{$this->maker->id}");
    expect(json_decode($mail[0]['payload'], true))->toEqual(['product_id' => $this->product->id, 'kind' => 'comment', 'bucket' => $bucket]);
    expect(json_encode([$bell, $mail]))->not->toContain('SECRET-BODY');
});

it('notifies nobody when the maker comments on their own product', function () {
    // Given the maker
    // When they comment on their own product
    producerComment($this->maker, $this->product);

    // Then nobody is notified
    expect(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
});

it('notifies the maker and the top-level author of a reply to a top-level comment', function () {
    // Given a top-level comment by Alice
    $top = producerComment($this->alice, $this->product);
    DB::table('notifications')->delete();
    DB::table('email_messages')->delete();

    // When Bob replies to it
    producerComment($this->bob, $this->product, $top);

    // Then the maker gets kind comment and Alice gets kind reply
    expect(producerRecipients())->toEqualCanonicalizing([[$this->maker->id, 'comment'], [$this->alice->id, 'reply']]);
    expect(DB::table('email_messages')->count())->toBe(2);
});

it('notifies the maker and the answered reply author for a reply to a reply', function () {
    // Given Alice's comment and Bob's reply to it
    $top = producerComment($this->alice, $this->product);
    $reply = producerComment($this->bob, $this->product, $top);
    DB::table('notifications')->delete();
    DB::table('email_messages')->delete();
    $carol = User::factory()->create();

    // When Carol answers Bob's reply
    producerComment($carol, $this->product, $reply);

    // Then the maker (comment) and Bob, the answered author (reply) are notified; Alice is not
    expect(producerRecipients())->toEqualCanonicalizing([[$this->maker->id, 'comment'], [$this->bob->id, 'reply']]);
});

it('notifies only the top-level author when the maker replies to a top-level comment', function () {
    // Given Alice's comment
    $top = producerComment($this->alice, $this->product);
    DB::table('notifications')->delete();
    DB::table('email_messages')->delete();

    // When the maker replies
    producerComment($this->maker, $this->product, $top);

    // Then only Alice is notified (the commenter is never notified)
    expect(producerRecipients())->toBe([[$this->alice->id, 'reply']]);
});

it('gives a person who is both the maker and the answered author one row of kind reply', function () {
    // Given the maker's own comment on the product... written by a second member first, then answered by the maker's own reply target
    $top = producerComment($this->maker, $this->product);
    DB::table('notifications')->delete();
    DB::table('email_messages')->delete();

    // When Alice replies to the maker's comment (the maker is both the maker and the answered author)
    producerComment($this->alice, $this->product, $top);

    // Then there is one row, of kind reply
    expect(producerRecipients())->toBe([[$this->maker->id, 'reply']]);
    expect(DB::table('email_messages')->count())->toBe(1);
});

it('notifies neither a suspended nor a deleted recipient, and gives an unverified one the bell only', function () {
    // Given a suspended maker
    DB::table('users')->where('id', $this->maker->id)->update(['suspended_at' => now()]);
    producerComment($this->alice, $this->product);
    expect(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);

    // And an unverified maker gets the bell and no mail
    DB::table('users')->where('id', $this->maker->id)->update(['suspended_at' => null, 'email_verified_at' => null]);
    producerComment($this->alice, $this->product);
    expect(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 0]);

    // And a deleted maker gets nothing more (the product is hidden then, so the listener runs for a comment written before)
    $comment = Comment::factory()->create(['product_id' => $this->product->id, 'user_id' => $this->alice->id]);
    DB::table('users')->where('id', $this->maker->id)->update(['deleted_at' => now()]);
    app(NotifyNewComment::class)->notify(new CommentCreated($comment->id, $this->product->id, $this->alice->id));
    expect(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 0]);
});

it('sends one mail per recipient, product, kind and UTC hour, but a bell row per comment', function () {
    // Given a frozen clock in the middle of an hour
    $this->travelTo(new DateTimeImmutable('2026-10-21 10:10:00 UTC'));

    // When two comments land in the same hour, then one in the next hour
    producerComment($this->alice, $this->product);
    $this->travelTo(new DateTimeImmutable('2026-10-21 10:50:00 UTC'));
    producerComment($this->bob, $this->product);
    $this->travelTo(new DateTimeImmutable('2026-10-21 11:05:00 UTC'));
    producerComment($this->alice, $this->product);

    // Then three bell rows, two mails (one per hour), keyed by the comment's own hour
    expect(DB::table('notifications')->count())->toBe(3);
    expect(DB::table('email_messages')->orderBy('idempotency_key')->pluck('idempotency_key')->all())->toBe([
        "new_comment:comment:{$this->product->id}:2026102110:{$this->maker->id}",
        "new_comment:comment:{$this->product->id}:2026102111:{$this->maker->id}",
    ]);
});

it('keeps separate mail buckets for the comment kind and the reply kind', function () {
    // Given a comment by Alice in an hour, and the maker as the one being notified
    $this->travelTo(new DateTimeImmutable('2026-10-21 10:10:00 UTC'));
    $maker = $this->maker;
    $top = producerComment($maker, $this->product);

    // When Alice replies to the maker's own comment and Bob comments, in the same hour
    producerComment($this->alice, $this->product, $top);
    producerComment($this->bob, $this->product);

    // Then the maker has a reply-kind mail and a comment-kind mail for the same hour
    expect(DB::table('email_messages')->where('user_id', $maker->id)->orderBy('idempotency_key')->pluck('idempotency_key')->all())->toBe([
        "new_comment:comment:{$this->product->id}:2026102110:{$maker->id}",
        "new_comment:reply:{$this->product->id}:2026102110:{$maker->id}",
    ]);
});

it('writes nothing for a removed or deleted comment, or a hidden product', function () {
    // Given a comment that was removed by staff before the listener ran, and one deleted by its author
    $removed = Comment::factory()->removed()->create(['product_id' => $this->product->id, 'user_id' => $this->alice->id]);
    $deleted = Comment::factory()->create(['product_id' => $this->product->id, 'user_id' => $this->alice->id, 'deleted_at' => now()]);
    $hidden = Product::factory()->takenDown()->create();
    $onHidden = Comment::factory()->create(['product_id' => $hidden->id, 'user_id' => $this->alice->id]);

    // When the listener runs for each
    foreach ([$removed, $deleted, $onHidden] as $comment) {
        app(NotifyNewComment::class)->notify(new CommentCreated($comment->id, $comment->product_id, $comment->user_id));
    }

    // Then nothing is written
    expect(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
});

it('writes nothing for a comment of a deleted account', function () {
    // Given a comment whose author deleted their account before the job ran
    $comment = Comment::factory()->create(['product_id' => $this->product->id, 'user_id' => $this->alice->id]);
    DB::table('users')->where('id', $this->alice->id)->update(['deleted_at' => now()]);

    // When the listener runs
    app(NotifyNewComment::class)->notify(new CommentCreated($comment->id, $this->product->id, $this->alice->id));

    // Then nothing is written
    expect(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
});

it('is idempotent for a repeated event and a re-run listener', function () {
    // Given a posted comment
    $id = producerComment($this->alice, $this->product);

    // When the event and the listener run again
    CommentCreated::dispatch($id, $this->product->id, $this->alice->id);
    app(NotifyNewComment::class)->notify(new CommentCreated($id, $this->product->id, $this->alice->id));

    // Then there is still one bell row and one mail
    expect(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 1]);
});
