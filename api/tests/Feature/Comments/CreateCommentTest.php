<?php

use App\Actions\Comment\CreateComment;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/CommentFixtures.php';

const MISSING_ULID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

it('creates a top-level comment: 201, one row, the counter moves, the author is shown', function () {
    // Given a member and a public product
    $member = signedInMember(['handle' => 'amina_r', 'name' => 'Amina']);
    $product = commentable();

    // When the member comments
    $response = commentPostRequest($product->id, ['body' => 'Great launch, congratulations!']);

    // Then there is one row, the counter is +1 and the resource describes it
    $response->assertCreated()
        ->assertJsonPath('data.state', 'visible')->assertJsonPath('data.body', 'Great launch, congratulations!')
        ->assertJsonPath('data.parent_id', null)->assertJsonPath('data.reply_to', null)
        ->assertJsonPath('data.author.handle', 'amina_r')->assertJsonPath('data.author.id', $member->id)->assertJsonPath('data.author.is_maker', false)
        ->assertJsonPath('data.edited_at', null)->assertJsonPath('data.replies_count', 0)
        ->assertJsonPath('data.can_edit', true)->assertJsonPath('data.can_delete', true);
    expect(array_keys($response->json('data')))->toBe(['id', 'parent_id', 'state', 'body', 'author', 'reply_to', 'created_at', 'edited_at', 'replies_count', 'can_edit', 'can_delete']);
    expect($response->json('data.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}/');
    expect(Comment::query()->count())->toBe(1)->and(productCommentCounters($product))->toBe([1, 1]);
    $row = Comment::query()->firstOrFail();
    expect($row->user_id)->toBe($member->id)->and($row->product_id)->toBe($product->id)->and($row->edited_at)->toBeNull()->and($row->replies_count)->toBe(0);
});

it('stores created_at with microseconds', function () {
    // Given a member and a product, and a clock with a fractional second
    signedInMember();
    $product = commentable();
    $this->travelTo(now()->microsecond(654321));

    // When the member comments
    commentPostRequest($product->id, ['body' => 'Timing matters'])->assertCreated();

    // Then the stored instant kept its microseconds (the keyset order needs them)
    expect(Comment::query()->firstOrFail()->created_at->micro)->toBe(654321);
});

it('replies to a top-level comment: parent set, no reply_to, both counters move', function () {
    // Given a top-level comment by somebody else
    signedInMember();
    $product = commentable();
    $top = Comment::factory()->create(['product_id' => $product->id]);
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 1]);

    // When the member replies to it
    $response = commentPostRequest($product->id, ['body' => 'Agreed!', 'parent_id' => $top->id]);

    // Then the reply hangs on the parent, answers nobody in particular and the counters are +1
    $response->assertCreated()->assertJsonPath('data.parent_id', $top->id)->assertJsonPath('data.reply_to', null);
    expect($top->fresh()->replies_count)->toBe(1)->and(productCommentCounters($product))->toBe([2, 2]);
    expect(Comment::query()->where('parent_id', $top->id)->firstOrFail()->reply_to_user_id)->toBeNull();
});

it('attaches a reply to a reply to the top-level ancestor and remembers who was answered', function () {
    // Given a top-level comment with a reply by another member
    $member = signedInMember();
    $product = commentable();
    $top = Comment::factory()->create(['product_id' => $product->id]);
    $replier = User::factory()->create(['handle' => 'sam_t', 'name' => 'Sam']);
    $reply = Comment::factory()->replyTo($top)->create(['user_id' => $replier->id]);
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 2]);
    DB::table('comments')->where('id', $top->id)->update(['replies_count' => 1]);

    // When the member answers the reply
    $response = commentPostRequest($product->id, ['body' => 'Good point, Sam', 'parent_id' => $reply->id]);

    // Then it attaches to the top-level comment, names the answered author, and only the ancestor counts it
    $response->assertCreated()->assertJsonPath('data.parent_id', $top->id)
        ->assertJsonPath('data.reply_to.id', $replier->id)->assertJsonPath('data.reply_to.handle', 'sam_t')->assertJsonPath('data.reply_to.name', 'Sam');
    expect(array_keys($response->json('data.reply_to')))->toBe(['id', 'handle', 'name']);
    expect($top->fresh()->replies_count)->toBe(2)->and($reply->fresh()->replies_count)->toBe(0)->and(productCommentCounters($product))->toBe([3, 3]);
    expect(Comment::query()->where('user_id', $member->id)->firstOrFail()->reply_to_user_id)->toBe($replier->id);
    assertCommentCountersConsistent();
});

it('lets the maker comment on their own product and marks the comment', function () {
    // Given a maker signed in on their own live product
    $maker = signedInMember();
    $product = commentable(['maker_id' => $maker->id]);

    // When they comment
    $response = commentPostRequest($product->id, ['body' => 'Thanks everyone for the support']);

    // Then it works and the author is flagged as the maker
    $response->assertCreated()->assertJsonPath('data.author.is_maker', true);
});

it('lets a suspended maker\'s product keep accepting comments', function () {
    // Given a product whose maker is suspended
    signedInMember();
    $product = commentable(['maker_id' => User::factory()->suspended()->create()->id]);

    // When a member comments
    // Then it is accepted (public content stays)
    commentPostRequest($product->id, ['body' => 'Still here'])->assertCreated();
});

it('accepts a comment on a product that launched a week ago or yesterday (there is no launch-day rule)', function (int $daysAgo) {
    // Given a product launched some days ago
    signedInMember();
    $product = Product::factory()->live(now('UTC')->subDays($daysAgo)->toDateString())->create();

    // When a member comments
    // Then it is accepted
    commentPostRequest($product->id, ['body' => 'Late to the party'])->assertCreated();
    expect(productCommentCounters($product))->toBe([1, 1]);
})->with([1, 7, 90]);

it('accepts a comment on a product launching today', function () {
    // Given a product whose launch day is today
    signedInMember();
    $product = Product::factory()->live(now('UTC')->toDateString())->create();

    // When / Then
    commentPostRequest($product->id, ['body' => 'First!'])->assertCreated();
});

it('answers every hidden product with the identical 404 body and writes nothing', function (string $case) {
    // Given a member and a product that is not public
    signedInMember();
    $deletedMaker = User::factory()->create(['deleted_at' => now()]);
    $id = match ($case) {
        'draft' => Product::factory()->complete()->create()->id,
        'pending' => Product::factory()->pending()->create()->id,
        'approved' => Product::factory()->approved()->create()->id,
        'scheduled' => Product::factory()->scheduled()->create()->id,
        'rejected' => Product::factory()->rejected()->create()->id,
        'changes requested' => Product::factory()->changesRequested()->create()->id,
        'taken down' => Product::factory()->takenDown()->create()->id,
        'soft deleted' => commentable(['deleted_at' => now()])->id,
        'deleted maker' => commentable(['maker_id' => $deletedMaker->id])->id,
        'launching tomorrow' => Product::factory()->live(now('UTC')->addDay()->toDateString())->create()->id,
        'unknown' => MISSING_ULID,
        'not a ulid' => 'nope',
        '200 chars' => str_repeat('a', 200),
        'nul byte' => '%00',
    };

    // When the member comments
    $response = commentPostRequest($id, ['body' => 'Hello there']);

    // Then it cannot be told apart from "no such product" and nothing is written
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect(Comment::query()->count())->toBe(0)->and(DB::table('products')->sum('comments_count'))->toBe(0);
})->with(['draft', 'pending', 'approved', 'scheduled', 'rejected', 'changes requested', 'taken down', 'soft deleted', 'deleted maker', 'launching tomorrow', 'unknown', 'not a ulid', '200 chars', 'nul byte']);

it('answers every unusable parent_id with the identical 404 body and writes nothing', function (string $case) {
    // Given a member, a product and a parent in some unusable state
    signedInMember();
    $product = commentable();
    $other = commentable();
    $deletedAuthor = User::factory()->create(['deleted_at' => now()]);
    $parent = match ($case) {
        'other product' => Comment::factory()->create(['product_id' => $other->id])->id,
        'removed' => Comment::factory()->removed()->create(['product_id' => $product->id])->id,
        'deleted' => Comment::factory()->deletedByAuthor()->create(['product_id' => $product->id])->id,
        'deleted account' => Comment::factory()->create(['product_id' => $product->id, 'user_id' => $deletedAuthor->id])->id,
        'unknown' => MISSING_ULID,
        'not a ulid' => 'nope',
        '200 chars' => str_repeat('a', 64),
    };
    $before = Comment::query()->count();

    // When the member replies to it
    $response = commentPostRequest($product->id, ['body' => 'Hello there', 'parent_id' => $parent]);

    // Then it is the uniform 404 and nothing changed
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect(Comment::query()->count())->toBe($before)->and(DB::table('products')->sum('comments_count'))->toBe(0);
})->with(['other product', 'removed', 'deleted', 'deleted account', 'unknown', 'not a ulid', '200 chars']);

it('refuses to answer a removed top-level comment directly but allows an answer to one of its visible replies', function () {
    // Given a staff-removed top-level comment that still has a visible reply
    signedInMember();
    $product = commentable();
    $top = Comment::factory()->removed()->create(['product_id' => $product->id]);
    $reply = Comment::factory()->replyTo($top)->create();
    DB::table('comments')->where('id', $top->id)->update(['replies_count' => 1]);
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 1]);

    // When the member answers the removed comment, and then the reply
    $direct = commentPostRequest($product->id, ['body' => 'To the husk', 'parent_id' => $top->id]);
    $viaReply = commentPostRequest($product->id, ['body' => 'To the reply', 'parent_id' => $reply->id]);

    // Then only the reply route works, and it counts on the (removed) ancestor
    $direct->assertNotFound();
    $viaReply->assertCreated()->assertJsonPath('data.parent_id', $top->id);
    expect($top->fresh()->replies_count)->toBe(2)->and(productCommentCounters($product))->toBe([2, 2]);
});

it('refuses guests, unverified and suspended members before looking at the product', function () {
    // Given a public product
    $product = commentable();

    // When a guest, an unverified member and a suspended member comment (existing and missing ids)
    $guest = commentPostRequest($product->id, ['body' => 'Hello there']);
    $guestMissing = commentPostRequest(MISSING_ULID, ['body' => 'Hello there']);
    $this->app['auth']->forgetGuards();
    signedInMember(['email_verified_at' => null]);
    $unverified = commentPostRequest($product->id, ['body' => 'Hello there']);
    $this->app['auth']->forgetGuards();
    signedInMember(['suspended_at' => now(), 'suspended_reason' => 'test']);
    $suspended = commentPostRequest($product->id, ['body' => 'Hello there']);
    $suspendedMissing = commentPostRequest(MISSING_ULID, ['body' => 'Hello there']);

    // Then each gets the guard's answer and nothing is written
    $guest->assertUnauthorized();
    $guestMissing->assertUnauthorized();
    $unverified->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $suspended->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    $suspendedMissing->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect(Comment::query()->count())->toBe(0);
});

it('refuses an author suspended or deleted after the request was authorised', function (string $column) {
    // Given a stale author model
    $author = User::factory()->create();
    $product = commentable();
    DB::table('users')->where('id', $author->id)->update([$column => now()]);

    // When the action runs with the stale model
    $attempt = fn () => app(CreateComment::class)->handle($author, $product->id, 'Hello there', null);

    // Then it is refused and nothing is written
    expect($attempt)->toThrow(AuthorizationException::class);
    expect(Comment::query()->count())->toBe(0)->and(productCommentCounters($product))->toBe([0, 0]);
})->with(['suspended_at', 'deleted_at']);

it('throws the documented exceptions when the action is called directly', function () {
    // Given an author
    $author = User::factory()->create();
    $product = commentable();

    // When the action gets a bad product or parent id
    // Then the refusals are typed
    expect(fn () => app(CreateComment::class)->handle($author, 'nope', 'Hello there', null))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(CreateComment::class)->handle($author, $product->id, 'Hello there', 'nope'))->toThrow(ModelNotFoundException::class);
});

it('cleans the body before storing it', function (string $sent, string $stored) {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When a body with odd whitespace or invisible characters is posted
    $response = commentPostRequest($product->id, ['body' => $sent]);

    // Then the cleaned text is stored and returned
    $response->assertCreated()->assertJsonPath('data.body', $stored);
    expect(Comment::query()->firstOrFail()->body)->toBe($stored);
})->with([
    'surrounding whitespace' => ["  hello world \n", 'hello world'],
    'crlf' => ["line one\r\nline two", "line one\nline two"],
    'three newlines' => ["a\n\n\n\nb", "a\n\nb"],
    'control chars' => ["he\x00ll\x07o", 'hello'],
    'bidi override' => ["he\u{202E}llo", 'hello'],
    'zero width space' => ["he\u{200B}llo", 'hello'],
    'hangul filler' => ["he\u{3164}llo", 'hello'],
    'zwnj and zwj kept' => ["a\u{200C}b\u{200D}c", "a\u{200C}b\u{200D}c"],
    'emoji kept' => ['Love it 🔥🚀', 'Love it 🔥🚀'],
    'tab becomes a space' => ["a\tb", 'a b'],
    'html is plain text' => ['<script>alert(1)</script> hi', '<script>alert(1)</script> hi'],
    'emoji only' => ['🔥', '🔥'],
]);

it('accepts the length edge and counts characters, not bytes', function () {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When bodies of exactly 1000 characters (ASCII and multibyte) are posted
    $ascii = commentPostRequest($product->id, ['body' => str_repeat('a', 1000)]);
    $multibyte = commentPostRequest($product->id, ['body' => str_repeat('é', 1000)]);

    // Then both are accepted
    $ascii->assertCreated();
    $multibyte->assertCreated();
});

it('refuses bad bodies with 422 and writes nothing', function (mixed $body) {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When an unusable body is posted
    $response = commentPostRequest($product->id, ['body' => $body]);

    // Then it is a validation error, never a 500
    $response->assertUnprocessable()->assertJsonValidationErrors('body');
    expect(Comment::query()->count())->toBe(0)->and(productCommentCounters($product))->toBe([0, 0]);
})->with([
    'whitespace only' => ['   '],
    'newlines only' => ["\n\n"],
    'bidi only' => ["\u{202E}\u{202D}"],
    'empty string' => [''],
    'null' => [null],
    'punctuation only' => ['!!!'],
    '1001 chars' => [str_repeat('a', 1001)],
    '1001 multibyte' => [str_repeat('é', 1001)],
    'four links' => ['http://a.example https://b.example HTTP://c.example https://d.example'],
    'four bare domains' => ['visit a.com b.org c.net d.io now'],
    'four www links' => ['www.a.example www.b.example www.c.example www.d.example'],
    'mixed forms' => ['https://a.example www.b.example c.example/path d.example'],
    'fullwidth schemes' => [mb_convert_kana('http://a.com http://b.com http://c.com http://d.com', 'A')],
    'fullwidth bare domains' => [mb_convert_kana('a.com b.org c.net d.io', 'A')],
    'array' => [['x']],
    'object' => [['a' => 'b']],
    'number' => [12345],
    'true' => [true],
    'false' => [false],
    'empty array' => [[]],
]);

it('accepts exactly three links', function () {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When a body with three links is posted
    // Then it is accepted
    commentPostRequest($product->id, ['body' => 'See http://a.example and https://b.example and https://c.example'])->assertCreated();
});

it('does not count ordinary prose as links', function (string $body) {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When prose with dots, versions and sentences ends is posted (more than three times over)
    $response = commentPostRequest($product->id, ['body' => $body]);

    // Then it is accepted: none of it is a link
    $response->assertCreated();
})->with([
    'sentence dot' => ['See you at 5pm. Bring snacks. Really. Truly. Madly.'],
    'version numbers' => ['Works on 3.5 and 4.0 and 5.1 and 6.2 and 7.3'],
    'abbreviations' => ['e.g. this, i.e. that, etc. and so on, a.m. or p.m.'],
    'ellipsis' => ['Well... maybe... who knows... right... ok'],
    'an email is not a link' => ['mail me at a@b.com or c@d.org or e@f.net or g@h.io'],
]);

it('stores a body with links verbatim (only the count is judged on the folded copy)', function () {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When a fullwidth link inside the cap is posted
    $original = "see \u{FF48}\u{FF54}\u{FF54}\u{FF50}\u{FF53}\u{FF1A}\u{FF0F}\u{FF0F}a.example";
    commentPostRequest($product->id, ['body' => $original])->assertCreated();

    // Then the original characters are stored, not the NFKC form
    expect(Comment::query()->firstOrFail()->body)->toBe($original);
});

it('refuses a missing body and bad parent_id shapes with 422', function (array $payload, string $field) {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When the payload is wrong
    $response = commentPostRequest($product->id, $payload);

    // Then it is a validation error on that field
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Comment::query()->count())->toBe(0);
})->with([
    'no body' => [[], 'body'],
    'parent array' => [['body' => 'Hello there', 'parent_id' => ['x']], 'parent_id'],
    'parent object' => [['body' => 'Hello there', 'parent_id' => ['a' => 'b']], 'parent_id'],
    'parent null' => [['body' => 'Hello there', 'parent_id' => null], 'parent_id'],
    'parent empty' => [['body' => 'Hello there', 'parent_id' => ''], 'parent_id'],
    'parent number' => [['body' => 'Hello there', 'parent_id' => 12], 'parent_id'],
    'parent bool' => [['body' => 'Hello there', 'parent_id' => true], 'parent_id'],
    'parent 65 chars' => [['body' => 'Hello there', 'parent_id' => str_repeat('a', 65)], 'parent_id'],
]);

it('ignores a stray extra field', function () {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When extra fields (including ones that look like columns) are sent
    $response = commentPostRequest($product->id, ['body' => 'Hello there', 'user_id' => MISSING_ULID, 'replies_count' => 99, 'removed_at' => now()->toIso8601String()]);

    // Then they are ignored
    $response->assertCreated();
    $row = Comment::query()->firstOrFail();
    expect($row->replies_count)->toBe(0)->and($row->removed_at)->toBeNull()->and($row->user_id)->not->toBe(MISSING_ULID);
});

it('answers a non-array JSON body (a bare string or list) with 422, never 500', function (string $raw) {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When a JSON document that is not an object is posted
    $response = $this->call('POST', "/api/v1/products/{$product->id}/comments", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw);

    // Then it is a validation error
    $response->assertUnprocessable();
})->with(['"text"', '[]', '[1,2]', 'null', '12']);

it('answers 405 to a verb the create url has no route for', function () {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When the url is read with a method that has no route yet (the public read arrives in the next task)
    // Then the verb is not a server error
    expect($this->putJson("/api/v1/products/{$product->id}/comments")->status())->toBe(405);
});

it('refuses invalid UTF-8 in the body and in parent_id with 422 (sent as a form, since JSON cannot carry it)', function (array $fields, string $field) {
    // Given a member and a product
    signedInMember();
    $product = commentable();

    // When a form post carries malformed bytes
    $response = $this->post("/api/v1/products/{$product->id}/comments", $fields, ['Accept' => 'application/json']);

    // Then it is a validation error on that field, never a 500
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Comment::query()->count())->toBe(0);
})->with([
    'body' => [['body' => "abc\xC3\x28 def"], 'body'],
    'parent_id' => [['body' => 'Hello there', 'parent_id' => "ab\xC3\x28"], 'parent_id'],
]);
