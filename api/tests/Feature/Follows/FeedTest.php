<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/FollowFixtures.php';
require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

/** A live product launched at `$launchedAt` (UTC) on that day, by `$maker`, with exactly the given topics. */
function feedProduct(User $maker, string $launchedAt = '2026-10-20 09:00:00', array $topics = [], array $attributes = []): Product
{
    $product = Product::factory()->live(substr($launchedAt, 0, 10))->create(['maker_id' => $maker->id, 'launched_at' => $launchedAt, ...$attributes]);
    DB::table('product_topics')->where('product_id', $product->id)->delete();
    foreach ($topics as $topic) {
        DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);
    }

    return $product;
}

function followTopicRow(User $user, Topic $topic): void
{
    DB::table('topic_follows')->insert(['user_id' => $user->id, 'topic_id' => $topic->id]);
}

/** @return list<string> ids served by the feed endpoint */
function feedIds(?string $query = ''): array
{
    return array_column(test()->getJson('/api/v1/me/feed'.$query)->assertOk()->json('data'), 'id');
}

it('shows launches of followed makers, from today and from earlier days, newest launch first', function () {
    // Given a member following a maker who launched on three days
    $me = signedInMember();
    $maker = User::factory()->create();
    followRow($me, $maker);
    $old = feedProduct($maker, '2026-10-10 09:00:00');
    $today = feedProduct($maker, '2026-10-21 08:00:00');
    $yesterday = feedProduct($maker, '2026-10-20 09:00:00');

    // When the feed is read
    // Then all three appear, newest first
    expect(feedIds())->toBe([$today->id, $yesterday->id, $old->id]);
});

it('shows products carrying a followed topic', function () {
    // Given a member following a topic, and a product with that topic and one without
    $me = signedInMember();
    $topic = Topic::factory()->create();
    followTopicRow($me, $topic);
    $with = feedProduct(User::factory()->create(), '2026-10-20 09:00:00', [$topic]);
    feedProduct(User::factory()->create(), '2026-10-20 10:00:00', [Topic::factory()->create()]);

    // When the feed is read
    // Then only the product with the followed topic is there
    expect(feedIds())->toBe([$with->id]);
});

it('never shows products that are not publicly visible', function (string $case) {
    // Given a member following a maker whose product is in a state that must stay out
    $me = signedInMember();
    $maker = User::factory()->create();
    followRow($me, $maker);
    $visible = feedProduct($maker, '2026-10-19 09:00:00');
    $hidden = match ($case) {
        'scheduled' => Product::factory()->scheduled()->create(['maker_id' => $maker->id]),
        'pending' => Product::factory()->pending()->create(['maker_id' => $maker->id]),
        'approved' => Product::factory()->approved()->create(['maker_id' => $maker->id]),
        'draft' => Product::factory()->create(['maker_id' => $maker->id]),
        'rejected' => Product::factory()->rejected()->create(['maker_id' => $maker->id]),
        'changes requested' => Product::factory()->changesRequested()->create(['maker_id' => $maker->id]),
        'taken down' => Product::factory()->takenDown()->create(['maker_id' => $maker->id, 'launched_at' => '2026-10-20 09:00:00']),
        'soft-deleted' => tap(feedProduct($maker, '2026-10-20 09:00:00'), fn (Product $p) => $p->delete()),
        'launched_at null' => feedProduct($maker, '2026-10-20 09:00:00', [], ['launched_at' => null]),
        'launch tomorrow' => Product::factory()->live('2026-10-22')->create(['maker_id' => $maker->id, 'launched_at' => '2026-10-21 11:00:00']),
    };

    // When the feed is read
    // Then only the visible one is there
    expect(feedIds())->toBe([$visible->id])->and(feedIds())->not->toContain($hidden->id);
})->with(['scheduled', 'pending', 'approved', 'draft', 'rejected', 'changes requested', 'taken down', 'soft-deleted', 'launched_at null', 'launch tomorrow']);

it('never shows the products of a deleted maker, but keeps those of a suspended one', function () {
    // Given a member following a deleted maker, a suspended maker and a normal maker, each with a launched product
    $me = signedInMember();
    $deleted = User::factory()->create(['deleted_at' => now()]);
    $suspended = User::factory()->suspended()->create();
    foreach ([$deleted, $suspended] as $maker) {
        followRow($me, $maker);
    }
    feedProduct($deleted, '2026-10-20 09:00:00');
    $kept = feedProduct($suspended, '2026-10-20 08:00:00');

    // When the feed is read
    // Then only the suspended maker's product is there
    expect(feedIds())->toBe([$kept->id]);
});

it('never shows the member\'s own products, even when they follow a topic the product carries', function () {
    // Given a member following a topic and carrying their own product with it
    $me = signedInMember();
    $topic = Topic::factory()->create();
    followTopicRow($me, $topic);
    feedProduct($me, '2026-10-20 09:00:00', [$topic]);
    $others = feedProduct(User::factory()->create(), '2026-10-20 08:00:00', [$topic]);

    // When the feed is read
    // Then only the other maker's product is there
    expect(feedIds())->toBe([$others->id]);
});

it('lists a product matching both the maker and a topic once, with the member reason first', function () {
    // Given a followed maker whose product carries two followed topics
    $me = signedInMember();
    $maker = User::factory()->create(['handle' => 'amina_r', 'name' => 'Amina']);
    followRow($me, $maker);
    $a = Topic::factory()->create(['slug' => 'alpha', 'name' => 'Alpha', 'position' => 1]);
    $b = Topic::factory()->create(['slug' => 'beta', 'name' => 'Beta', 'position' => 2]);
    followTopicRow($me, $a);
    followTopicRow($me, $b);
    $product = feedProduct($maker, '2026-10-20 09:00:00', [$b, $a]);

    // When the feed is read
    $data = $this->getJson('/api/v1/me/feed')->assertOk()->json('data');

    // Then there is one card with the member reason first, then the topics in position order
    expect($data)->toHaveCount(1)->and($data[0]['id'])->toBe($product->id);
    expect($data[0]['reasons'])->toBe([
        ['type' => 'member', 'user' => ['id' => $maker->id, 'handle' => 'amina_r', 'name' => 'Amina']],
        ['type' => 'topic', 'topic' => ['id' => $a->id, 'slug' => 'alpha', 'name' => 'Alpha']],
        ['type' => 'topic', 'topic' => ['id' => $b->id, 'slug' => 'beta', 'name' => 'Beta']],
    ]);
});

it('gives at most three topic reasons', function () {
    // Given a product with five followed topics
    $me = signedInMember();
    $topics = collect(range(1, 5))->map(fn (int $i) => Topic::factory()->create(['position' => $i]));
    foreach ($topics as $t) {
        followTopicRow($me, $t);
    }
    feedProduct(User::factory()->create(), '2026-10-20 09:00:00', $topics->all());

    // When the feed is read
    $reasons = $this->getJson('/api/v1/me/feed')->assertOk()->json('data.0.reasons');

    // Then only the first three topics (by position) are named
    expect($reasons)->toHaveCount(3)->and(array_column(array_column($reasons, 'topic'), 'id'))->toBe($topics->take(3)->pluck('id')->all());
});

it('pages through ties on launched_at by keyset, every product once in order', function () {
    // Given a followed maker with seven products, five of them launched at the same second
    $me = signedInMember();
    $maker = User::factory()->create();
    followRow($me, $maker);
    foreach (range(1, 5) as $_) {
        feedProduct($maker, '2026-10-20 09:00:00');
    }
    feedProduct($maker, '2026-10-20 09:00:01');
    feedProduct($maker, '2026-10-19 09:00:00');
    $expected = Product::query()->visible()->orderByDesc('launched_at')->orderByDesc('id')->pluck('id')->all();

    // When the feed is walked two at a time
    $ids = [];
    $url = '/api/v1/me/feed?per_page=2';
    $guard = 20;
    while ($url !== null && $guard-- > 0) {
        $page = $this->getJson($url)->assertOk();
        array_push($ids, ...array_column($page->json('data'), 'id'));
        $next = $page->json('links.next');
        $url = $next === null ? null : (string) parse_url($next, PHP_URL_PATH).'?'.parse_url($next, PHP_URL_QUERY);
    }

    // Then every product appears exactly once, in launched_at desc, id desc order
    expect($ids)->toBe($expected)->and($ids)->toHaveCount(7);
});

it('shows a follow and an unfollow at once: nothing is cached', function () {
    // Given a maker with a launched product
    $me = signedInMember();
    $maker = User::factory()->create();
    $product = feedProduct($maker, '2026-10-20 09:00:00');

    // When the member follows, reads, unfollows and reads again
    $before = feedIds();
    $this->putJson("/api/v1/users/{$maker->handle}/follow")->assertOk();
    $during = feedIds();
    $this->deleteJson("/api/v1/users/{$maker->handle}/follow")->assertOk();
    $after = feedIds();

    // Then each read reflects the follow state immediately
    expect([$before, $during, $after])->toBe([[], [$product->id], []]);
});

it('is not influenced by anyone else\'s follows', function () {
    // Given another member following a maker, and me following nobody
    $me = signedInMember();
    $other = User::factory()->create();
    $maker = User::factory()->create();
    followRow($other, $maker);
    feedProduct($maker, '2026-10-20 09:00:00');

    // When I read my feed
    // Then it is empty
    expect(feedIds())->toBe([]);
});

it('reports has_follows for the empty states', function (string $setup, bool $hasFollows, int $count) {
    // Given a member in one of four situations
    $me = signedInMember();
    $maker = User::factory()->create();
    $topic = Topic::factory()->create();
    match ($setup) {
        'none' => null,
        'follows but nothing launched' => followRow($me, $maker),
        'only a topic' => (function () use ($me, $topic) {
            followTopicRow($me, $topic);
            feedProduct(User::factory()->create(), '2026-10-20 09:00:00', [$topic]);
        })(),
        'only a member' => (function () use ($me, $maker) {
            followRow($me, $maker);
            feedProduct($maker, '2026-10-20 09:00:00');
        })(),
    };

    // When the feed is read
    $response = $this->getJson('/api/v1/me/feed')->assertOk();

    // Then the list and meta.has_follows tell the situation
    expect($response->json('data'))->toHaveCount($count)->and($response->json('meta.has_follows'))->toBe($hasFollows);
})->with([
    'none' => ['none', false, 0],
    'follows but nothing launched' => ['follows but nothing launched', true, 0],
    'only a topic' => ['only a topic', true, 1],
    'only a member' => ['only a member', true, 1],
]);

it('pins the card keys and reflects my vote and the counters', function () {
    // Given a followed maker's product that I voted for, with counters set
    $me = signedInMember();
    $maker = User::factory()->create();
    followRow($me, $maker);
    $voted = feedProduct($maker, '2026-10-20 09:00:00', [], ['votes_count' => 4, 'comments_count' => 2]);
    $plain = feedProduct($maker, '2026-10-19 09:00:00');
    Vote::factory()->create(['user_id' => $me->id, 'product_id' => $voted->id]);

    // When the feed is read
    $data = $this->getJson('/api/v1/me/feed')->assertOk()->json('data');

    // Then the keys are the card plus the feed fields, and has_voted/counters are right
    expect(array_keys($data[0]))->toEqualCanonicalizing(['id', 'slug', 'name', 'tagline', 'description', 'website_url', 'logo_url', 'launch_date', 'launched_at', 'votes_count', 'comments_count', 'has_voted', 'maker', 'topics', 'reasons']);
    expect($data[0]['has_voted'])->toBeTrue()->and($data[0]['votes_count'])->toBe(4)->and($data[0]['comments_count'])->toBe(2)->and($data[1]['has_voted'])->toBeFalse();
    expect($data[0]['id'])->toBe($voted->id)->and($data[1]['id'])->toBe($plain->id);
});

it('exposes no email, role or flags in the reasons or the maker card', function () {
    // Given a followed maker
    $me = signedInMember();
    $maker = User::factory()->role(Role::Reviewer)->create(['email' => 'secret@example.com']);
    followRow($me, $maker);
    feedProduct($maker, '2026-10-20 09:00:00');

    // When the feed is read
    $body = $this->getJson('/api/v1/me/feed')->assertOk()->getContent();

    // Then nothing private leaks
    expect($body)->not->toContain('secret@example.com')->not->toContain('"role"')->not->toContain('suspended_reason');
});

it('is no-store and private, and needs a signed-in, active, verified member', function () {
    // Given a signed-in member
    $me = signedInMember();

    // When the feed is read, and then by a guest, an unverified and a suspended member
    $ok = $this->getJson('/api/v1/me/feed');
    $this->app['auth']->forgetGuards();
    $guest = $this->getJson('/api/v1/me/feed');
    Sanctum::actingAs(User::factory()->unverified()->create());
    $unverified = $this->getJson('/api/v1/me/feed');
    Sanctum::actingAs(User::factory()->suspended()->create());
    $suspended = $this->getJson('/api/v1/me/feed');

    // Then the member's answer is never cached and the guards answer
    expect($ok->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $guest->assertUnauthorized();
    $unverified->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $suspended->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('rejects tampered cursors and per_page with 422, never 500', function (array $query) {
    // Given a member
    signedInMember();

    // When the feed is read with a bad query
    // Then it is 422
    $this->getJson('/api/v1/me/feed?'.http_build_query($query))->assertStatus(422);
})->with(function () {
    $encode = fn (array $c) => rtrim(strtr(base64_encode(json_encode($c)), '+/', '-_'), '=');

    return [
        'per_page 0' => [['per_page' => 0]], 'per_page 51' => [['per_page' => 51]], 'per_page text' => [['per_page' => 'abc']], 'per_page array' => [['per_page' => ['1']]],
        'per_page object' => [['per_page' => ['a' => 1]]],
        'cursor not base64' => [['cursor' => '!!!']], 'cursor array' => [['cursor' => ['x']]],
        'cursor wrong keys' => [['cursor' => $encode(['created_at' => '2026-10-01 00:00:00', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', '_pointsToNextItems' => true])]],
        'cursor impossible date' => [['cursor' => $encode(['launched_at' => '2026-02-31 00:00:00', 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', '_pointsToNextItems' => true])]],
        'cursor bad ulid' => [['cursor' => $encode(['launched_at' => '2026-10-01 00:00:00', 'id' => 'nope', '_pointsToNextItems' => true])]],
        'cursor null' => [['cursor' => $encode(['launched_at' => null, 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', '_pointsToNextItems' => true])]],
    ];
});

it('treats an empty cursor as page one and ignores a JSON body on the GET', function () {
    // Given a followed maker's product
    $me = signedInMember();
    $maker = User::factory()->create();
    followRow($me, $maker);
    $product = feedProduct($maker, '2026-10-20 09:00:00');

    // When the feed is read with ?cursor= and with a hostile JSON body
    $empty = $this->getJson('/api/v1/me/feed?cursor=');
    $body = $this->call('GET', '/api/v1/me/feed', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['per_page' => ['x'], 'cursor' => 5]));

    // Then both are the first page
    $empty->assertOk()->assertJsonPath('data.0.id', $product->id);
    $body->assertOk()->assertJsonPath('data.0.id', $product->id);
});

it('runs the same number of queries for a short and a long feed with many reasons', function () {
    // Given a member following a maker and five topics
    $me = signedInMember();
    $maker = User::factory()->create();
    followRow($me, $maker);
    $topics = collect(range(1, 5))->map(fn () => Topic::factory()->create());
    foreach ($topics as $t) {
        followTopicRow($me, $t);
    }
    $queries = function (): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        test()->getJson('/api/v1/me/feed?per_page=50')->assertOk();

        return $n;
    };
    foreach (range(1, 3) as $i) {
        feedProduct($maker, "2026-10-20 09:00:0{$i}", $topics->all());
    }
    $small = $queries();

    // When thirty more products arrive
    foreach (range(1, 30) as $i) {
        feedProduct($maker, '2026-10-19 09:00:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), $topics->all());
    }

    // Then the query count is unchanged
    expect($queries())->toBe($small);
});

it('runs no products query for a member who follows nothing', function () {
    // Given a member with no follows, and launched products around
    signedInMember();
    feedProduct(User::factory()->create(), '2026-10-20 09:00:00');
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    // When the feed is read
    $response = $this->getJson('/api/v1/me/feed')->assertOk();

    // Then the page is empty, has_follows is false and the products table was never queried
    expect($response->json('data'))->toBe([])->and($response->json('meta.has_follows'))->toBeFalse();
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'from "products"')))->toBeFalse();
    expect($response->json('links.next'))->toBeNull();
});

it('pages across both branches, with an item matching both appearing once, and walks back with links.prev', function () {
    // Given a followed maker and a followed topic whose products interleave in time; one product matches both
    $me = signedInMember();
    $maker = User::factory()->create();
    $other = User::factory()->create();
    $topic = Topic::factory()->create();
    followRow($me, $maker);
    followTopicRow($me, $topic);
    $expected = [
        feedProduct($maker, '2026-10-20 12:00:00')->id,
        feedProduct($other, '2026-10-20 11:00:00', [$topic])->id,
        feedProduct($maker, '2026-10-20 10:00:00', [$topic])->id, // matches both branches
        feedProduct($other, '2026-10-20 09:00:00', [$topic])->id,
        feedProduct($maker, '2026-10-20 09:00:00')->id, // ties with the previous on launched_at
        feedProduct($other, '2026-10-19 09:00:00', [$topic])->id,
        feedProduct($maker, '2026-10-18 09:00:00')->id,
    ];
    $expected = Product::query()->whereIn('id', $expected)->orderByDesc('launched_at')->orderByDesc('id')->pluck('id')->all();
    feedProduct(User::factory()->create(), '2026-10-20 08:00:00', [Topic::factory()->create()]); // matches nothing

    // When walked forward two at a time, collecting the links
    $ids = [];
    $pages = [];
    $url = '/api/v1/me/feed?per_page=2';
    for ($guard = 0; $url !== null && $guard < 10; $guard++) {
        $page = $this->getJson($url)->assertOk();
        $pages[] = $page->json();
        array_push($ids, ...array_column($page->json('data'), 'id'));
        $next = $page->json('links.next');
        $url = $next === null ? null : (string) parse_url($next, PHP_URL_PATH).'?'.parse_url($next, PHP_URL_QUERY);
    }

    // Then every product appears once in order, and the last page links back to the previous one with identical rows
    expect($ids)->toBe($expected)->and($pages)->toHaveCount(4);
    $prev = $pages[3]['links']['prev'];
    $back = $this->getJson((string) parse_url($prev, PHP_URL_PATH).'?'.parse_url($prev, PHP_URL_QUERY))->assertOk();
    expect(array_column($back->json('data'), 'id'))->toBe(array_column($pages[2]['data'], 'id'));
});
