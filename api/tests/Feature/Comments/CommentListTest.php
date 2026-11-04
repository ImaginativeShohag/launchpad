<?php

use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

function commentsUrl(Product $product, string $query = ''): string
{
    return "/api/v1/products/{$product->slug}/comments".($query === '' ? '' : '?'.$query);
}

/** @return list<string> */
function commentListedIds(Product $product, string $query = ''): array
{
    return array_column(test()->getJson(commentsUrl($product, $query))->assertOk()->json('data'), 'id');
}

/** A product with top-level comments that tie on every sort key somewhere; returns [product, comments by label]. */
function commentTiedThread(): array
{
    $product = commentable();
    // Ids are minted in creation order, so the later-created row has the larger id: ties on (replies_count, created_at)
    // must therefore resolve to the LATER-created row first (id desc), which is the opposite of creation order.
    $c = [];
    $c['two_a'] = topComment($product, '2026-10-21 10:00:00.500000', 2);
    $c['two_b'] = topComment($product, '2026-10-21 10:00:00.500000', 2);          // identical to the microsecond
    $c['two_c'] = topComment($product, '2026-10-21 10:00:00.500001', 2);          // one microsecond later, same second
    $c['two_old'] = topComment($product, '2026-10-21 09:00:00', 2);
    $c['seven'] = topComment($product, '2026-10-20 08:00:00', 7);                  // the oldest but the most discussed
    $c['zero_new'] = topComment($product, '2026-10-21 11:59:00', 0);
    $c['zero_old'] = topComment($product, '2026-10-21 10:00:00.499999', 0);
    $c['huge'] = topComment($product, '2026-10-19 08:00:00', 999999);

    return [$product, $c];
}

it('orders sort=top by replies_count, then newer first, then larger id first, with ties on every key', function () {
    // Given comments that tie on replies_count, on created_at to the microsecond, and a few that do not
    [$product, $c] = commentTiedThread();

    // When the list is read with the default and with sort=top
    $default = commentListedIds($product);
    $top = commentListedIds($product, 'sort=top');

    // Then the order is replies desc, created_at desc (microseconds count), id desc
    $expected = [$c['huge']->id, $c['seven']->id, $c['two_c']->id, $c['two_b']->id, $c['two_a']->id, $c['two_old']->id, $c['zero_new']->id, $c['zero_old']->id];
    expect($default)->toBe($expected)->and($top)->toBe($expected);
    expect($c['two_b']->id > $c['two_a']->id)->toBeTrue();
});

it('orders sort=newest by created_at desc then id desc', function () {
    // Given the same tied comments
    [$product, $c] = commentTiedThread();

    // When read with sort=newest
    $ids = commentListedIds($product, 'sort=newest');

    // Then the newest come first, microsecond ties by id desc
    expect($ids)->toBe([$c['zero_new']->id, $c['two_c']->id, $c['two_b']->id, $c['two_a']->id, $c['zero_old']->id, $c['two_old']->id, $c['seven']->id, $c['huge']->id]);
});

it('walks the whole list with per_page=2 exactly once and in order, for both sorts (int cursors 0 and large included)', function (string $sort) {
    // Given the tied comments
    [$product, $c] = commentTiedThread();
    $whole = commentListedIds($product, "sort={$sort}&per_page=50");

    // When the pages are followed
    $walked = walkCommentPages("/api/v1/products/{$product->slug}/comments?sort={$sort}&per_page=2");

    // Then every row appears exactly once, in the same order as the single page
    expect($walked)->toBe($whole)->and($walked)->toHaveCount(8)->and(array_unique($walked))->toHaveCount(8);
})->with(['top', 'newest']);

it('keeps the framework cursor shape: data, links and meta', function () {
    // Given a few comments
    [$product] = commentTiedThread();

    // When the first small page is read
    $page = $this->getJson(commentsUrl($product, 'per_page=3'))->assertOk();

    // Then the envelope has a next cursor
    expect(array_keys($page->json()))->toBe(['data', 'links', 'meta']);
    expect($page->json('links.next'))->not->toBeNull()->and($page->json('meta.per_page'))->toBe(3)->and($page->json('data'))->toHaveCount(3);
});

it('defaults to 20 per page and allows at most 50', function () {
    // Given 25 comments
    $product = commentable();
    foreach (range(1, 25) as $i) {
        topComment($product, '2026-10-21 10:00:00', 0, ['body' => "Comment {$i}"]);
    }

    // When read without per_page, with 50 and with 51
    $default = $this->getJson(commentsUrl($product));
    $max = $this->getJson(commentsUrl($product, 'per_page=50'));
    $over = $this->getJson(commentsUrl($product, 'per_page=51'));

    // Then the default is 20, 50 is allowed and 51 is a validation error
    expect($default->json('data'))->toHaveCount(20)->and($max->json('data'))->toHaveCount(25);
    $over->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('lists a removed or deleted top-level comment as a placeholder only while it has replies', function () {
    // Given placeholders with and without replies, and a plain comment
    $product = commentable();
    $removedWith = topComment($product, '2026-10-21 10:00:00', 0, ['removed_at' => now(), 'removed_by' => User::factory()->create()->id, 'removed_reason' => 'spam', 'body' => 'Secret removed text']);
    $deletedWith = topComment($product, '2026-10-21 10:01:00', 0, ['deleted_at' => now(), 'body' => '']);
    $removedWithout = topComment($product, '2026-10-21 10:02:00', 0, ['removed_at' => now(), 'removed_by' => User::factory()->create()->id, 'removed_reason' => 'spam']);
    $deletedWithout = topComment($product, '2026-10-21 10:03:00', 0, ['deleted_at' => now(), 'body' => '']);
    $plain = topComment($product, '2026-10-21 10:04:00');
    addReplies($removedWith, 1);
    addReplies($deletedWith, 2);

    // When a guest reads the list
    $response = $this->getJson(commentsUrl($product, 'sort=newest'))->assertOk();
    $byId = collect($response->json('data'))->keyBy('id');

    // Then the husks with replies are listed without body or author, with their replies; the others are absent
    expect($byId->keys()->all())->toEqualCanonicalizing([$plain->id, $deletedWith->id, $removedWith->id]);
    expect($byId[$removedWith->id])->toMatchArray(['state' => 'removed', 'body' => null, 'author' => null, 'replies_count' => 1]);
    expect($byId[$removedWith->id]['replies'])->toHaveCount(1);
    expect($byId[$deletedWith->id])->toMatchArray(['state' => 'deleted', 'body' => null, 'author' => null, 'replies_count' => 2]);
    expect($byId[$deletedWith->id]['replies'])->toHaveCount(2);
    expect($response->getContent())->not->toContain('Secret removed text')->not->toContain('removed_by')->not->toContain('removed_reason');
});

it('masks a top-level comment whose author deleted their account until the clean-up job runs', function () {
    // Given a comment by a deleted account with replies, one without, and a reply by a deleted account
    $product = commentable();
    $gone = User::factory()->create(['deleted_at' => now()]);
    $withReplies = topComment($product, '2026-10-21 10:00:00', 0, ['user_id' => $gone->id, 'body' => 'Personal words']);
    $withoutReplies = topComment($product, '2026-10-21 10:01:00', 0, ['user_id' => $gone->id, 'body' => 'More personal words']);
    $visibleTop = topComment($product, '2026-10-21 10:02:00');
    addReplies($withReplies, 1);
    addReplies($visibleTop, 1, '2026-10-21 10:10:00');
    $byGone = Comment::factory()->replyTo($visibleTop)->create(['user_id' => $gone->id, 'body' => 'Reply by a ghost']);
    DB::table('comments')->where('id', $visibleTop->id)->update(['replies_count' => 2]);

    // When a guest reads the list
    $response = $this->getJson(commentsUrl($product, 'sort=newest'))->assertOk();
    $byId = collect($response->json('data'))->keyBy('id');

    // Then the first is a deleted placeholder with its reply, the second is absent, the ghost's reply is absent
    expect($byId->keys()->all())->toEqualCanonicalizing([$withReplies->id, $visibleTop->id]);
    expect($byId[$withReplies->id])->toMatchArray(['state' => 'deleted', 'body' => null, 'author' => null]);
    expect(collect($byId[$visibleTop->id]['replies'])->pluck('id')->all())->not->toContain($byGone->id);
    expect($response->getContent())->not->toContain('Personal words')->not->toContain('Reply by a ghost');
});

it('renders a suspended author reduced and keeps the comment', function () {
    // Given a comment by a suspended member with an avatar
    $product = commentable();
    $suspended = User::factory()->suspended()->create(['handle' => 'sus_pended', 'name' => 'Sus Pended', 'avatar_path' => 'avatars/x.webp']);
    topComment($product, '2026-10-21 10:00:00', 0, ['user_id' => $suspended->id, 'body' => 'Still public']);

    // When a guest reads the list
    $author = $this->getJson(commentsUrl($product))->assertOk()->json('data.0');

    // Then the comment stays and the author keeps handle and name only
    expect($author['body'])->toBe('Still public');
    expect($author['author'])->toMatchArray(['id' => $suspended->id, 'handle' => 'sus_pended', 'name' => 'Sus Pended', 'avatar_url' => null, 'is_suspended' => true]);
});

it('embeds the first three replies oldest first, with the true replies_count, and an empty array for none', function () {
    // Given a thread with 5 replies and one without
    $product = commentable();
    $busy = topComment($product, '2026-10-21 10:00:00');
    $quiet = topComment($product, '2026-10-21 09:00:00');
    $replies = addReplies($busy, 5);

    // When the list is read
    $data = collect($this->getJson(commentsUrl($product, 'sort=newest'))->assertOk()->json('data'))->keyBy('id');

    // Then exactly the three oldest replies are embedded and replies_count says 5
    expect($data[$busy->id]['replies_count'])->toBe(5);
    expect(array_column($data[$busy->id]['replies'], 'id'))->toBe([$replies[0]->id, $replies[1]->id, $replies[2]->id]);
    expect($data[$quiet->id]['replies'])->toBe([])->and($data[$quiet->id]['replies_count'])->toBe(0);
    expect($data[$busy->id]['replies'][0])->not->toHaveKey('replies');
});

it('leaves out removed, deleted and ghost replies from the embedded replies', function () {
    // Given a thread whose first replies are unusable
    $product = commentable();
    $top = topComment($product, '2026-10-21 10:00:00');
    $remover = User::factory()->create();
    addReplies($top, 1, '2026-10-21 10:00:00', ['removed_at' => now(), 'removed_by' => $remover->id, 'removed_reason' => 'spam']);
    addReplies($top, 1, '2026-10-21 10:00:10', ['deleted_at' => now(), 'body' => '']);
    $good = addReplies($top, 2, '2026-10-21 10:00:20');

    // When the list is read
    $embedded = $this->getJson(commentsUrl($product))->assertOk()->json('data.0.replies');

    // Then only the visible replies appear
    expect(array_column($embedded, 'id'))->toBe([$good[0]->id, $good[1]->id]);
});

it('uses a constant number of queries however many threads the page has', function () {
    // Given a product with 2 threads, and another with 8 threads of 4 replies each
    $small = commentable();
    foreach (range(1, 2) as $i) {
        addReplies(topComment($small, '2026-10-21 10:00:00'), 4);
    }
    $large = commentable();
    foreach (range(1, 8) as $i) {
        addReplies(topComment($large, '2026-10-21 10:00:00'), 4);
    }
    $this->getJson(commentsUrl($small))->assertOk(); // warms the settings cache
    $count = function (Product $product): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $this->getJson(commentsUrl($product))->assertOk();

        return $n;
    };

    // When the queries of each read are counted
    $a = $count($small);
    $b = $count($large);

    // Then there is no N+1
    expect($b)->toBe($a);
});

it('shows can_edit and can_delete only to a known viewer, about their own visible comments', function () {
    // Given a member's fresh comment, an older one outside the window, and someone else's comment
    $member = User::factory()->create();
    $product = commentable();
    $fresh = topComment($product, now()->subMinutes(5)->toDateTimeString(), 0, ['user_id' => $member->id]);
    $old = topComment($product, now()->subMinutes(16)->toDateTimeString(), 0, ['user_id' => $member->id]);
    $foreign = topComment($product, now()->subMinutes(1)->toDateTimeString());

    // When a guest and then the member read the list
    $guest = collect($this->getJson(commentsUrl($product))->json('data'))->keyBy('id');
    Sanctum::actingAs($member);
    $own = collect($this->getJson(commentsUrl($product))->json('data'))->keyBy('id');

    // Then the guest sees no can_* keys, the member can edit the fresh one only and delete both of theirs
    expect($guest[$fresh->id])->not->toHaveKeys(['can_edit', 'can_delete']);
    expect([$own[$fresh->id]['can_edit'], $own[$fresh->id]['can_delete']])->toBe([true, true]);
    expect([$own[$old->id]['can_edit'], $own[$old->id]['can_delete']])->toBe([false, true]);
    expect([$own[$foreign->id]['can_edit'], $own[$foreign->id]['can_delete']])->toBe([false, false]);
});

it('flags the maker\'s comments with is_maker and treats a suspended caller as a guest', function () {
    // Given the maker's comment and a suspended member who also commented
    $product = commentable();
    topComment($product, '2026-10-21 10:00:00', 0, ['user_id' => $product->maker_id]);
    $suspended = User::factory()->suspended()->create();
    topComment($product, '2026-10-21 10:01:00', 0, ['user_id' => $suspended->id]);

    // When the suspended member's token reads the list
    Sanctum::actingAs($suspended);
    $response = $this->getJson(commentsUrl($product, 'sort=newest'));

    // Then it is a guest answer (no can_* keys, default caching) and the maker is flagged
    $response->assertOk();
    expect(collect($response->json('data'))->firstWhere('author.id', $product->maker_id)['author']['is_maker'])->toBeTrue();
    expect($response->json('data.0'))->not->toHaveKey('can_edit');
    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('sets the viewer cache policy: Vary always, no-store only for a known viewer', function () {
    // Given a product with a comment
    $product = commentable();
    topComment($product, '2026-10-21 10:00:00');

    // When a guest and a member read the list
    $guest = $this->getJson(commentsUrl($product));
    Sanctum::actingAs(User::factory()->create());
    $member = $this->getJson(commentsUrl($product));

    // Then both vary on credentials, only the member's is not storable
    expect($guest->headers->get('Vary'))->toContain('Authorization')->toContain('Cookie');
    expect($guest->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($member->headers->get('Vary'))->toContain('Authorization');
    expect($member->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('gives an anonymous request only the device cookie, and a stateful origin the framework session cookies', function () {
    // Given a product with a comment
    $product = commentable();
    topComment($product, '2026-10-21 10:00:00');

    // When read without an Origin and from the SPA origin
    $plain = $this->getJson(commentsUrl($product));
    $stateful = spa()->getJson(commentsUrl($product));

    // Then the plain answer sets only lp_did, the SPA one also the session and XSRF cookies (as on every public API read)
    expect(collect($plain->headers->getCookies())->map->getName()->all())->toBe(['lp_did']);
    expect(collect($stateful->headers->getCookies())->map->getName()->all())->toContain('lp_did', 'XSRF-TOKEN');
});

it('answers hidden, unknown and malformed products with the identical 404', function (string $case) {
    // Given a product in some hidden state (or a bad slug)
    $deletedMaker = User::factory()->create(['deleted_at' => now()]);
    $slug = match ($case) {
        'draft' => Product::factory()->complete()->create()->slug,
        'pending' => Product::factory()->pending()->create()->slug,
        'scheduled' => Product::factory()->scheduled()->create()->slug,
        'taken down' => Product::factory()->takenDown()->create()->slug,
        'launching tomorrow' => Product::factory()->live(now('UTC')->addDay()->toDateString())->create()->slug,
        'soft deleted' => commentable(['deleted_at' => now()])->slug,
        'deleted maker' => commentable(['maker_id' => $deletedMaker->id])->slug,
        'unknown' => 'no-such-product',
        'nul byte' => 'bad%00slug',
        '200 chars' => str_repeat('a', 200),
    };

    // When the comments are read
    $response = $this->getJson("/api/v1/products/{$slug}/comments");

    // Then it is the uniform 404
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
})->with(['draft', 'pending', 'scheduled', 'taken down', 'launching tomorrow', 'soft deleted', 'deleted maker', 'unknown', 'nul byte', '200 chars']);

it('refuses bad sort, per_page and cursor values with 422', function (string $query) {
    // Given a product with comments
    $product = commentable();
    topComment($product, '2026-10-21 10:00:00');

    // When the list is read with a bad query
    $response = $this->getJson(commentsUrl($product, $query));

    // Then it is a validation error, never a 500
    $response->assertUnprocessable();
})->with([
    'sort hot' => ['sort=hot'], 'sort empty' => ['sort='], 'sort array' => ['sort[]=x'], 'sort object' => ['sort[a]=b'],
    'per_page 0' => ['per_page=0'], 'per_page 51' => ['per_page=51'], 'per_page abc' => ['per_page=abc'], 'per_page array' => ['per_page[]=1'], 'per_page float' => ['per_page=1.5'],
    'cursor array' => ['cursor[]=x'], 'cursor not base64' => ['cursor=!!!'], 'cursor json null' => ['cursor=bnVsbA'],
]);

it('refuses tampered or mismatched cursors with 422', function (string $name, string $sort) {
    // Given a product with comments
    $product = commentable();
    $ulid = (string) Str::ulid();
    $date = '2026-10-18T10:00:00.000000Z';
    $encode = fn (array $payload) => rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    $cursor = match ($name) {
        'missing flag' => $encode(['replies_count' => 1, 'comments.created_at' => $date, 'comments.id' => $ulid]),
        'wrong keys' => $encode(['x' => 1, 'created_at' => $date, 'id' => $ulid, '_pointsToNextItems' => true]),
        'top cursor on newest' => $encode(['replies_count' => 1, 'created_at' => $date, 'id' => $ulid, '_pointsToNextItems' => true]),
        'newest cursor on top' => $encode(['created_at' => $date, 'id' => $ulid, '_pointsToNextItems' => true]),
        'replies as string' => $encode(['replies_count' => '3', 'created_at' => $date, 'id' => $ulid, '_pointsToNextItems' => true]),
        'replies negative' => $encode(['replies_count' => -1, 'created_at' => $date, 'id' => $ulid, '_pointsToNextItems' => true]),
        'replies too large' => $encode(['replies_count' => 1000001, 'created_at' => $date, 'id' => $ulid, '_pointsToNextItems' => true]),
        'date as string' => $encode(['replies_count' => 1, 'created_at' => 'yesterday', 'id' => $ulid, '_pointsToNextItems' => true]),
        'feb 31' => $encode(['replies_count' => 1, 'created_at' => '2026-02-31T10:00:00.000000Z', 'id' => $ulid, '_pointsToNextItems' => true]),
        'id not ulid' => $encode(['replies_count' => 1, 'created_at' => $date, 'id' => 'abc', '_pointsToNextItems' => true]),
    };

    // When it is sent with the sort it does or does not fit
    $response = $this->getJson(commentsUrl($product, "sort={$sort}&cursor={$cursor}"));

    // Then it is a validation error on the cursor
    $response->assertUnprocessable()->assertJsonValidationErrors('cursor');
})->with([
    ['missing flag', 'top'], ['wrong keys', 'top'], ['top cursor on newest', 'newest'], ['newest cursor on top', 'top'], ['replies as string', 'top'],
    ['replies negative', 'top'], ['replies too large', 'top'], ['date as string', 'top'], ['feb 31', 'top'], ['id not ulid', 'top'],
]);

it('treats an empty cursor as the first page', function () {
    // Given comments
    [$product] = commentTiedThread();

    // When read with ?cursor=
    // Then it is page one
    expect(commentListedIds($product, 'cursor=&per_page=3'))->toBe(array_slice(commentListedIds($product), 0, 3));
});

it('ignores a JSON body on GET', function () {
    // Given a product with a comment
    $product = commentable();
    $comment = topComment($product, '2026-10-21 10:00:00');

    // When a body of hostile values is sent with the GET
    foreach ([['sort' => 'hot', 'per_page' => 999, 'cursor' => 'bnVsbA'], ['sort' => ['x'], 'cursor' => ['y']], ['cursor' => '!!!']] as $body) {
        $response = $this->call('GET', commentsUrl($product), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body));

        // Then the answer is the plain first page
        $response->assertOk();
        expect(array_column($response->json('data'), 'id'))->toBe([$comment->id]);
    }
});

it('stays visible for a product launching today and one launched long ago, and lists nothing for a product without comments', function () {
    // Given a product launching today and an old one, neither with comments
    $today = Product::factory()->live(now('UTC')->toDateString())->create();
    $old = Product::factory()->live(now('UTC')->subDays(200)->toDateString())->create();

    // When their comments are read
    // Then both answer 200 with an empty list
    expect($this->getJson(commentsUrl($today))->assertOk()->json('data'))->toBe([]);
    expect($this->getJson(commentsUrl($old))->assertOk()->json('data'))->toBe([]);
});

it('answers 405 to writes on the slug url', function () {
    // Given a product
    $product = commentable();

    // When the list url gets a verb it has no route for
    // Then it is a 405, not a 500
    expect($this->putJson(commentsUrl($product), [])->status())->toBe(405);
});
