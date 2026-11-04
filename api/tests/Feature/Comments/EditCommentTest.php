<?php

use App\Actions\Comment\EditComment;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

function commentEditRequest(string $id, mixed $body)
{
    return test()->patchJson("/api/v1/comments/{$id}", ['body' => $body]);
}

/** A counted top-level comment by `$author` on a public product, created `$minutesAgo` minutes ago. */
function commentOwnedBy(User $author, int $minutesAgo = 0, ?Product $product = null): Comment
{
    $product ??= commentable();
    DB::table('products')->where('id', $product->id)->increment('comments_count');

    return Comment::factory()->createdAt(now()->subMinutes($minutesAgo))->create(['product_id' => $product->id, 'user_id' => $author->id, 'body' => 'Original text']);
}

it('lets the author edit inside the window: body replaced, edited_at set, nothing else moves', function () {
    // Given a comment written five minutes ago
    $author = signedInMember();
    $comment = commentOwnedBy($author, 5);
    $createdAt = $comment->created_at->toIso8601String();

    // When the author edits it
    $response = commentEditRequest($comment->id, 'Better text');

    // Then it is saved, marked as edited, and counts and created_at are untouched
    $response->assertOk()->assertJsonPath('data.body', 'Better text')->assertJsonPath('data.state', 'visible')->assertJsonPath('data.can_edit', true);
    expect($response->json('data.edited_at'))->not->toBeNull();
    $row = $comment->fresh();
    expect($row->body)->toBe('Better text')->and($row->edited_at)->not->toBeNull()->and($row->created_at->toIso8601String())->toBe($createdAt)->and($row->updated_at->gt($row->created_at))->toBeTrue();
    expect(productCommentCounters($row->product_id))->toBe([1, 1]);
});

it('lets the author edit again inside the window', function () {
    // Given a comment edited once
    $author = signedInMember();
    $comment = commentOwnedBy($author, 1);
    commentEditRequest($comment->id, 'First edit')->assertOk();

    // When it is edited a second and third time
    commentEditRequest($comment->id, 'Second edit')->assertOk();
    commentEditRequest($comment->id, 'Third edit')->assertOk();

    // Then the last text stands
    expect($comment->fresh()->body)->toBe('Third edit');
});

it('closes the window after the setting: 409 edit_window_closed and the body is unchanged', function () {
    // Given a comment written 16 minutes ago and the default window of 15
    $author = signedInMember();
    $comment = commentOwnedBy($author, 16);

    // When the author edits it
    $response = commentEditRequest($comment->id, 'Too late');

    // Then it is refused with the stable code and nothing changed
    $response->assertStatus(409)->assertJsonPath('code', 'edit_window_closed');
    expect($comment->fresh()->body)->toBe('Original text')->and($comment->fresh()->edited_at)->toBeNull();
});

it('treats the boundary minute as open and the next instant as closed', function () {
    // Given a comment written exactly 15 minutes ago
    $author = signedInMember();
    $comment = commentOwnedBy($author, 15);

    // When it is edited at the boundary, and one second later
    $atBoundary = commentEditRequest($comment->id, 'At the boundary');
    $this->travel(1)->seconds();
    $after = commentEditRequest($comment->id, 'One second late');

    // Then the boundary is still open and the next second is closed
    $atBoundary->assertOk();
    $after->assertStatus(409);
});

it('reads the window setting live', function () {
    // Given a comment written 30 minutes ago and an admin who widens the window to 60
    $author = signedInMember();
    $comment = commentOwnedBy($author, 30);
    commentEditRequest($comment->id, 'Closed at 15')->assertStatus(409);
    $admin = User::factory()->role(Role::Admin)->create();
    app(Settings::class)->set(SettingKey::CommunityCommentEditMinutes, 60, $admin);

    // When the author edits again
    // Then the new window applies at once
    commentEditRequest($comment->id, 'Open at 60')->assertOk();

    // And with the window at 0 even a fresh comment cannot be edited
    app(Settings::class)->set(SettingKey::CommunityCommentEditMinutes, 0, $admin);
    $fresh = commentOwnedBy($author, 0);
    commentEditRequest($fresh->id, 'Never')->assertStatus(409)->assertJsonPath('code', 'edit_window_closed');
    expect($fresh->fresh()->body)->toBe('Original text');
});

it('answers a no-op edit with 200 without bumping edited_at, even after the window', function () {
    // Given a comment written two hours ago
    $author = signedInMember();
    $comment = commentOwnedBy($author, 120);
    $updatedAt = $comment->updated_at->toIso8601String();

    // When the same body is sent again
    $response = commentEditRequest($comment->id, 'Original text');

    // Then it is a 200 with the comment unchanged
    $response->assertOk()->assertJsonPath('data.body', 'Original text')->assertJsonPath('data.edited_at', null);
    $row = $comment->fresh();
    expect($row->edited_at)->toBeNull()->and($row->updated_at->toIso8601String())->toBe($updatedAt);
});

it('treats a body that cleans to the stored text as a no-op', function () {
    // Given a comment
    $author = signedInMember();
    $comment = commentOwnedBy($author, 1);

    // When the same text arrives padded with whitespace
    commentEditRequest($comment->id, "  Original text \r\n")->assertOk();

    // Then edited_at stays null
    expect($comment->fresh()->edited_at)->toBeNull();
});

it('answers someone else\'s comment, a missing id and a bad id with the identical 404', function (string $case) {
    // Given a comment by another member, and the staff, maker and stranger signed in in turn
    $author = User::factory()->create();
    $product = commentable();
    $comment = commentOwnedBy($author, 1, $product);
    match ($case) {
        'staff' => signedInStaff(Role::Admin),
        'maker' => commentSignInAsMakerOf($product),
        default => signedInMember(),
    };
    $id = match ($case) {
        'missing' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'not a ulid' => 'nope',
        '200 chars' => str_repeat('a', 200),
        default => $comment->id,
    };

    // When the member edits it
    $response = commentEditRequest($id, 'Hijacked');

    // Then the answer is the uniform 404 and the body is untouched
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect($comment->fresh()->body)->toBe('Original text');
})->with(['stranger', 'staff', 'maker', 'missing', 'not a ulid', '200 chars']);

it('answers a removed, author-deleted or hidden-product comment with 404', function (string $case) {
    // Given the author and a comment in some unusable state
    $author = signedInMember();
    $product = $case === 'hidden product' ? Product::factory()->takenDown()->create() : commentable();
    $comment = match ($case) {
        'removed' => Comment::factory()->removed()->create(['product_id' => $product->id, 'user_id' => $author->id]),
        'deleted' => Comment::factory()->deletedByAuthor()->create(['product_id' => $product->id, 'user_id' => $author->id]),
        'hidden product' => Comment::factory()->create(['product_id' => $product->id, 'user_id' => $author->id]),
    };

    // When the author edits it
    $response = commentEditRequest($comment->id, 'Hello again');

    // Then it is the uniform 404 and nothing changed
    $response->assertNotFound();
    expect($comment->fresh()->body)->toBe($case === 'deleted' ? '' : $comment->body);
})->with(['removed', 'deleted', 'hidden product']);

it('edits a reply the same way', function () {
    // Given the author's reply to a top-level comment
    $author = signedInMember();
    $top = Comment::factory()->create(['product_id' => ($product = commentable())->id]);
    $reply = Comment::factory()->replyTo($top)->createdAt(now()->subMinute())->create(['user_id' => $author->id, 'body' => 'Original text']);

    // When the author edits it
    // Then it works and keeps its parent
    commentEditRequest($reply->id, 'Edited reply')->assertOk()->assertJsonPath('data.parent_id', $top->id);
    expect($reply->fresh()->parent_id)->toBe($top->id);
});

it('applies the body rules and answers 422 for every bad shape', function (mixed $body) {
    // Given the author and a comment
    $author = signedInMember();
    $comment = commentOwnedBy($author, 1);

    // When a bad body is sent
    $response = commentEditRequest($comment->id, $body);

    // Then it is a validation error and the text is unchanged
    $response->assertUnprocessable()->assertJsonValidationErrors('body');
    expect($comment->fresh()->body)->toBe('Original text');
})->with([
    'empty' => [''], 'null' => [null], 'whitespace' => ['   '], 'punctuation' => ['...'], 'array' => [['x']], 'object' => [['a' => 'b']],
    'number' => [5], 'bool' => [true], '1001 chars' => [str_repeat('a', 1001)],
    'four links' => ['http://a.example http://b.example http://c.example http://d.example'],
]);

it('answers 422 for a missing body, a non-object document and a stray extra field', function () {
    // Given the author and a comment
    $author = signedInMember();
    $comment = commentOwnedBy($author, 1);

    // When no body, a bare string document and extra fields are sent
    $missing = $this->patchJson("/api/v1/comments/{$comment->id}", []);
    $bare = $this->call('PATCH', "/api/v1/comments/{$comment->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '"text"');
    $extra = $this->patchJson("/api/v1/comments/{$comment->id}", ['body' => 'With extras', 'user_id' => 'x', 'removed_at' => 'now']);

    // Then the first two are validation errors and the third ignores the extras
    $missing->assertUnprocessable();
    $bare->assertUnprocessable();
    $extra->assertOk();
    expect($comment->fresh()->removed_at)->toBeNull();
});

it('applies the route guards: guest 401, unverified and suspended 403 before any lookup', function () {
    // Given a comment
    $comment = Comment::factory()->create();

    // When a guest, an unverified and a suspended member edit it (and a missing id)
    $guest = commentEditRequest($comment->id, 'Hello there');
    $this->app['auth']->forgetGuards();
    signedInMember(['email_verified_at' => null]);
    $unverified = [commentEditRequest($comment->id, 'Hello there'), commentEditRequest('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'Hello there')];
    $this->app['auth']->forgetGuards();
    signedInMember(['suspended_at' => now(), 'suspended_reason' => 'x']);
    $suspended = [commentEditRequest($comment->id, 'Hello there'), commentEditRequest('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'Hello there')];

    // Then each class gets one answer regardless of the id
    $guest->assertUnauthorized();
    foreach ($unverified as $response) {
        $response->assertForbidden()->assertJsonPath('code', 'email_unverified');
    }
    foreach ($suspended as $response) {
        $response->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    }
});

it('refuses an author suspended or deleted after the request was authorised', function (string $column) {
    // Given a stale author model whose account changed
    $author = User::factory()->create();
    $comment = commentOwnedBy($author, 1);
    DB::table('users')->where('id', $author->id)->update([$column => now()]);

    // When the action runs with the stale model
    $attempt = fn () => app(EditComment::class)->handle($author, $comment->id, 'Late edit');

    // Then it is refused and the text is unchanged
    expect($attempt)->toThrow(AuthorizationException::class);
    expect($comment->fresh()->body)->toBe('Original text');
})->with(['suspended_at', 'deleted_at']);

/** Signs in the maker of a product. */
function commentSignInAsMakerOf(Product $product): User
{
    $maker = $product->maker;
    Sanctum::actingAs($maker);

    return $maker;
}

it('refuses invalid UTF-8 in the body with 422 (sent as a form, since JSON cannot carry it)', function () {
    // Given the author and a comment
    $author = signedInMember();
    $comment = commentOwnedBy($author, 1);

    // When a form patch carries malformed bytes
    $response = $this->patch("/api/v1/comments/{$comment->id}", ['body' => "a\xC3\x28b"], ['Accept' => 'application/json']);

    // Then it is a validation error and the text is unchanged
    $response->assertUnprocessable()->assertJsonValidationErrors('body');
    expect($comment->fresh()->body)->toBe('Original text');
});

it('formats created_at and edited_at identically: UTC, microseconds, Z', function () {
    // Given a comment written at a known microsecond instant and a clock with its own microseconds
    $author = signedInMember();
    $product = commentable();
    $comment = Comment::factory()->createdAt(CarbonImmutable::parse('2026-10-21 11:55:00.123456', 'UTC'))->create(['product_id' => $product->id, 'user_id' => $author->id, 'body' => 'Original text']);
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 1]);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00.654321', 'UTC'));

    // When the author edits it
    $data = commentEditRequest($comment->id, 'Edited text')->assertOk()->json('data');

    // Then both stamps share one format and keep their microseconds
    expect($data['created_at'])->toBe('2026-10-21T11:55:00.123456Z')->and($data['edited_at'])->toBe('2026-10-21T12:00:00.654321Z');
});
