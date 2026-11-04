<?php

use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

function commentRepliesUrl(string $commentId, string $query = ''): string
{
    return "/api/v1/comments/{$commentId}/replies".($query === '' ? '' : '?'.$query);
}

it('pages all replies oldest first with per_page=2, each exactly once, and the first page repeats the embedded ones', function () {
    // Given a thread with 7 replies, three of them tied to the microsecond
    $product = commentable();
    $top = topComment($product, '2026-10-21 09:00:00');
    $first = addReplies($top, 4, '2026-10-21 10:00:00');
    $tieA = Comment::factory()->replyTo($top)->create(['created_at' => CarbonImmutable::parse('2026-10-21 10:05:00.250000', 'UTC'), 'updated_at' => now()]);
    $tieB = Comment::factory()->replyTo($top)->create(['created_at' => CarbonImmutable::parse('2026-10-21 10:05:00.250000', 'UTC'), 'updated_at' => now()]);
    $tieC = Comment::factory()->replyTo($top)->create(['created_at' => CarbonImmutable::parse('2026-10-21 10:05:00.250000', 'UTC'), 'updated_at' => now()]);
    DB::table('comments')->where('id', $top->id)->update(['replies_count' => 7]);
    $expected = [...array_map(fn ($c) => $c->id, $first), $tieA->id, $tieB->id, $tieC->id];

    // When the replies are walked 2 at a time, and the list is read for the embedded ones
    $walked = walkCommentPages(commentRepliesUrl($top->id, 'per_page=2'));
    $embedded = array_column($this->getJson("/api/v1/products/{$product->slug}/comments")->json('data.0.replies'), 'id');

    // Then every reply appears once, oldest first (ties by id ascending), and the first page starts with the embedded three
    expect($walked)->toBe($expected);
    expect(array_slice($walked, 0, 3))->toBe($embedded);
});

it('has the framework envelope, replies without children, and defaults to 20 per page', function () {
    // Given a thread with 22 replies
    $product = commentable();
    $top = topComment($product, '2026-10-21 09:00:00');
    addReplies($top, 22);

    // When the first page is read
    $page = $this->getJson(commentRepliesUrl($top->id))->assertOk();

    // Then it is 20 replies in the envelope, none of them carrying replies of their own
    expect(array_keys($page->json()))->toBe(['data', 'links', 'meta'])->and($page->json('data'))->toHaveCount(20);
    expect($page->json('data.0'))->not->toHaveKey('replies')->and($page->json('data.0.parent_id'))->toBe($top->id);
    $this->getJson(commentRepliesUrl($top->id, 'per_page=51'))->assertUnprocessable();
});

it('leaves out removed, deleted and ghost replies', function () {
    // Given a thread with unusable replies among good ones
    $product = commentable();
    $top = topComment($product, '2026-10-21 09:00:00');
    addReplies($top, 1, '2026-10-21 10:00:00', ['removed_at' => now(), 'removed_by' => User::factory()->create()->id, 'removed_reason' => 'x']);
    addReplies($top, 1, '2026-10-21 10:00:10', ['deleted_at' => now(), 'body' => '']);
    addReplies($top, 1, '2026-10-21 10:00:20', ['user_id' => User::factory()->create(['deleted_at' => now()])->id]);
    $good = addReplies($top, 2, '2026-10-21 10:00:30');

    // When the replies are read
    $ids = array_column($this->getJson(commentRepliesUrl($top->id))->assertOk()->json('data'), 'id');

    // Then only the visible ones are returned
    expect($ids)->toBe([$good[0]->id, $good[1]->id]);
});

it('serves the replies of a removed or deleted top-level comment (the placeholder keeps its thread)', function () {
    // Given a staff-removed top-level comment with a reply
    $product = commentable();
    $top = topComment($product, '2026-10-21 09:00:00', 0, ['removed_at' => now(), 'removed_by' => User::factory()->create()->id, 'removed_reason' => 'x']);
    $reply = addReplies($top, 1)[0];

    // When its replies are read
    // Then the reply is served
    expect(array_column($this->getJson(commentRepliesUrl($top->id))->assertOk()->json('data'), 'id'))->toBe([$reply->id]);
});

it('answers a reply id, an unknown id, a bad id and a hidden product with the identical 404', function (string $case) {
    // Given a thread on a product in some state
    $product = match ($case) {
        'taken down' => Product::factory()->takenDown()->create(),
        'launching tomorrow' => Product::factory()->live(now('UTC')->addDay()->toDateString())->create(),
        'soft deleted' => commentable(['deleted_at' => now()]),
        'deleted maker' => commentable(['maker_id' => User::factory()->create(['deleted_at' => now()])->id]),
        default => commentable(),
    };
    $top = topComment($product, '2026-10-21 09:00:00');
    $reply = addReplies($top, 1)[0];
    $id = match ($case) {
        'a reply id' => $reply->id,
        'unknown' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'not a ulid' => 'nope',
        '200 chars' => str_repeat('a', 200),
        'nul byte' => '%00',
        default => $top->id,
    };

    // When the replies are read
    $response = $this->getJson(commentRepliesUrl($id));

    // Then it is the uniform 404
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
})->with(['a reply id', 'unknown', 'not a ulid', '200 chars', 'nul byte', 'taken down', 'launching tomorrow', 'soft deleted', 'deleted maker']);

it('refuses bad per_page and cursors with 422, treats an empty cursor as page one and ignores a JSON body', function () {
    // Given a thread
    $product = commentable();
    $top = topComment($product, '2026-10-21 09:00:00');
    $reply = addReplies($top, 1)[0];

    // When bad queries are sent
    foreach (['per_page=0', 'per_page=abc', 'per_page[]=1', 'cursor[]=x', 'cursor=bnVsbA', 'cursor=!!!'] as $query) {
        $this->getJson(commentRepliesUrl($top->id, $query))->assertUnprocessable();
    }
    $encode = fn (array $payload) => rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $topShape = $encode(['replies_count' => 1, 'created_at' => '2026-10-18T10:00:00.000000Z', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', '_pointsToNextItems' => true]);
    $this->getJson(commentRepliesUrl($top->id, "cursor={$topShape}"))->assertUnprocessable()->assertJsonValidationErrors('cursor');

    // Then they are validation errors; the empty cursor and a hostile body give the plain first page
    expect(array_column($this->getJson(commentRepliesUrl($top->id, 'cursor='))->assertOk()->json('data'), 'id'))->toBe([$reply->id]);
    $body = $this->call('GET', commentRepliesUrl($top->id), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['per_page' => 999, 'cursor' => '!!!']));
    expect(array_column($body->assertOk()->json('data'), 'id'))->toBe([$reply->id]);
});

it('is viewer aware: can_edit and can_delete for the author, none for a guest, a suspended caller is a guest', function () {
    // Given a member's fresh reply
    $member = User::factory()->create();
    $product = commentable();
    $top = topComment($product, '2026-10-21 09:00:00');
    $mine = addReplies($top, 1, now()->subMinutes(3)->toDateTimeString(), ['user_id' => $member->id])[0];

    // When a guest, the author and a suspended caller read the replies
    $guest = $this->getJson(commentRepliesUrl($top->id));
    Sanctum::actingAs($member);
    $author = $this->getJson(commentRepliesUrl($top->id));
    Sanctum::actingAs(User::factory()->suspended()->create());
    $suspended = $this->getJson(commentRepliesUrl($top->id));

    // Then only the author sees the keys, with the right values and cache headers
    expect($guest->json('data.0'))->not->toHaveKeys(['can_edit', 'can_delete']);
    expect([$author->json('data.0.can_edit'), $author->json('data.0.can_delete')])->toBe([true, true]);
    expect($suspended->json('data.0'))->not->toHaveKey('can_edit');
    expect($guest->headers->get('Vary'))->toContain('Authorization')->and($guest->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($author->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    expect($mine->id)->toBe($author->json('data.0.id'));
});

it('shows reply_to for a reply that answered a reply, and hides it when that account is deleted', function () {
    // Given a reply answering another member, and one answering a member who deleted their account
    $product = commentable();
    $top = topComment($product, '2026-10-21 09:00:00');
    $answered = User::factory()->create(['handle' => 'sam_t', 'name' => 'Sam']);
    $ghost = User::factory()->create(['deleted_at' => now()]);
    $one = Comment::factory()->replyTo($top, $answered)->create(['created_at' => now()->subMinutes(2), 'updated_at' => now()]);
    $two = Comment::factory()->replyTo($top, $ghost)->create(['created_at' => now()->subMinute(), 'updated_at' => now()]);
    DB::table('comments')->where('id', $top->id)->update(['replies_count' => 2]);

    // When the replies are read
    $data = collect($this->getJson(commentRepliesUrl($top->id))->assertOk()->json('data'))->keyBy('id');

    // Then the live account is named and the deleted one is not
    expect($data[$one->id]['reply_to'])->toBe(['id' => $answered->id, 'handle' => 'sam_t', 'name' => 'Sam']);
    expect($data[$two->id]['reply_to'])->toBeNull();
});
