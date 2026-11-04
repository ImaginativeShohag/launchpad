<?php

use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/FollowFixtures.php';
require_once __DIR__.'/../../Support/CommentFixtures.php';

/** Walks `/me/follows/users` pages by following links.next; returns the handles in served order. */
function followedHandles(string $url): array
{
    $handles = [];
    $guard = 50;
    while ($url !== null && $guard-- > 0) {
        $page = test()->getJson($url)->assertOk();
        array_push($handles, ...array_column($page->json('data'), 'handle'));
        $next = $page->json('links.next');
        $url = $next === null ? null : (string) parse_url($next, PHP_URL_PATH).'?'.parse_url($next, PHP_URL_QUERY);
    }

    return $handles;
}

it('lists the members I follow, newest first, with the documented keys', function () {
    // Given a member who followed three people at different times
    $me = signedInMember();
    $a = User::factory()->create(['handle' => 'first', 'headline' => 'Hi']);
    $b = User::factory()->create(['handle' => 'second']);
    $c = User::factory()->create(['handle' => 'third']);
    followRow($me, $a, '2026-10-01 10:00:00');
    followRow($me, $b, '2026-10-02 10:00:00');
    followRow($me, $c, '2026-10-03 10:00:00');

    // When the list is read
    $response = $this->getJson('/api/v1/me/follows/users')->assertOk();

    // Then it is newest first and each row has exactly the documented keys
    expect(array_column($response->json('data'), 'handle'))->toBe(['third', 'second', 'first']);
    expect(array_keys($response->json('data.2')))->toEqualCanonicalizing(['id', 'handle', 'name', 'headline', 'avatar_url', 'is_suspended', 'followed_at']);
    $response->assertJsonPath('data.2.headline', 'Hi');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('never shows another member\'s follows', function () {
    // Given two members with different follows
    $me = signedInMember();
    $other = User::factory()->create();
    followRow($other, User::factory()->create(['handle' => 'theirs']));
    followRow($me, User::factory()->create(['handle' => 'mine']));

    // When I read my list
    // Then only mine is there
    expect(array_column($this->getJson('/api/v1/me/follows/users')->json('data'), 'handle'))->toBe(['mine']);
});

it('reduces a suspended followed member like the public profile', function () {
    // Given a followed member who was suspended
    $me = signedInMember();
    $suspended = User::factory()->suspended()->create(['headline' => 'Buy now', 'avatar_path' => 'avatars/x.webp']);
    followRow($me, $suspended);

    // When the list is read
    $row = $this->getJson('/api/v1/me/follows/users')->assertOk()->json('data.0');

    // Then headline and avatar are hidden and the flag is set
    expect($row['is_suspended'])->toBeTrue()->and($row['headline'])->toBeNull()->and($row['avatar_url'])->toBeNull();
});

it('walks the whole list by keyset with ties on created_at, every row once in order', function () {
    // Given five follows, three of them with the same instant
    $me = signedInMember();
    $members = User::factory()->count(5)->create();
    $times = ['2026-10-05 10:00:00', '2026-10-05 10:00:00', '2026-10-05 10:00:00', '2026-10-04 10:00:00', '2026-10-06 10:00:00.123456'];
    foreach ($members as $i => $m) {
        followRow($me, $m, $times[$i]);
    }
    $expected = DB::table('user_follows')->where('follower_id', $me->id)->orderByDesc('user_follows.created_at')->orderByDesc('user_follows.followed_id')
        ->join('users', 'users.id', '=', 'user_follows.followed_id')->pluck('users.handle')->all();

    // When walked two at a time
    $walked = followedHandles('/api/v1/me/follows/users?per_page=2');

    // Then every row appears exactly once, in the keyset order, and the second link kept the page size
    expect($walked)->toBe($expected)->and($walked)->toHaveCount(5);
    $second = $this->getJson($this->getJson('/api/v1/me/follows/users?per_page=2')->json('links.next'))->assertOk();
    expect($second->json('data'))->toHaveCount(2);
});

it('rejects tampered cursors and per_page with 422, and treats an empty cursor as page one', function (array $query) {
    // Given a member with a follow
    $me = signedInMember();
    followRow($me, User::factory()->create());

    // When the list is read with a bad query
    // Then it is 422, never 500
    $this->getJson('/api/v1/me/follows/users?'.http_build_query($query))->assertStatus(422);
})->with([
    'per_page 0' => [['per_page' => 0]], 'per_page 101' => [['per_page' => 101]], 'per_page text' => [['per_page' => 'abc']], 'per_page array' => [['per_page' => ['1']]],
    'cursor not base64' => [['cursor' => '!!!']], 'cursor array' => [['cursor' => ['x']]],
    'cursor wrong keys' => [['cursor' => rtrim(strtr(base64_encode(json_encode(['id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', '_pointsToNextItems' => true])), '+/', '-_'), '=')]],
    'cursor impossible date' => [['cursor' => rtrim(strtr(base64_encode(json_encode(['created_at' => '2026-02-31 00:00:00', 'followed_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', '_pointsToNextItems' => true])), '+/', '-_'), '=')]],
    'cursor bad ulid' => [['cursor' => rtrim(strtr(base64_encode(json_encode(['created_at' => '2026-10-01 00:00:00', 'followed_id' => 'nope', '_pointsToNextItems' => true])), '+/', '-_'), '=')]],
]);

it('treats an empty cursor as the first page and ignores a JSON body on a GET', function () {
    // Given a member with a follow
    $me = signedInMember();
    followRow($me, User::factory()->create(['handle' => 'one']));

    // When the list is read with ?cursor= and with a hostile JSON body
    $empty = $this->getJson('/api/v1/me/follows/users?cursor=');
    $body = $this->call('GET', '/api/v1/me/follows/users', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['per_page' => ['x'], 'cursor' => 5]));

    // Then both are the first page
    $empty->assertOk()->assertJsonPath('data.0.handle', 'one');
    $body->assertOk()->assertJsonPath('data.0.handle', 'one');
});

it('lists my followed topics ordered by position then name, none of anyone else\'s', function () {
    // Given topics with positions, one followed by another member only
    $me = signedInMember();
    $late = Topic::factory()->create(['name' => 'Zed', 'position' => 5]);
    $early = Topic::factory()->create(['name' => 'Beta', 'position' => 1]);
    $alsoEarly = Topic::factory()->create(['name' => 'Alpha', 'position' => 1]);
    $notMine = Topic::factory()->create(['position' => 0]);
    foreach ([$late, $early, $alsoEarly] as $t) {
        DB::table('topic_follows')->insert(['user_id' => $me->id, 'topic_id' => $t->id]);
    }
    DB::table('topic_follows')->insert(['user_id' => User::factory()->create()->id, 'topic_id' => $notMine->id]);

    // When the topics list is read
    $response = $this->getJson('/api/v1/me/follows/topics')->assertOk();

    // Then it is position then name, and only mine, in the topic resource shape
    expect(array_column($response->json('data'), 'name'))->toBe(['Alpha', 'Beta', 'Zed']);
    expect(array_keys($response->json('data.0')))->toEqualCanonicalizing(['id', 'slug', 'name', 'emoji', 'description', 'position']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('requires a signed-in, active, verified member for both lists', function (string $uri) {
    // Given no one, then an unverified, then a suspended member
    $guest = $this->getJson($uri);
    Sanctum::actingAs(User::factory()->unverified()->create());
    $unverified = $this->getJson($uri);
    Sanctum::actingAs(User::factory()->suspended()->create());
    $suspended = $this->getJson($uri);

    // Then the guards answer
    $guest->assertUnauthorized();
    $unverified->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $suspended->assertForbidden()->assertJsonPath('code', 'account_unavailable');
})->with(['/api/v1/me/follows/users', '/api/v1/me/follows/topics']);

it('serves the users list from its keyset index without a sort', function () {
    // Given an analysed table with plenty of follows from many members
    $me = User::factory()->create();
    $members = User::factory()->count(300)->create();
    $rows = [];
    foreach ($members as $i => $m) {
        $rows[] = ['follower_id' => $me->id, 'followed_id' => $m->id, 'created_at' => now()->subSeconds($i % 40)];
        $rows[] = ['follower_id' => $m->id, 'followed_id' => $me->id, 'created_at' => now()->subSeconds($i)];
    }
    DB::table('user_follows')->insert($rows);
    DB::statement('ANALYZE user_follows');
    Sanctum::actingAs($me);
    $first = $this->getJson('/api/v1/me/follows/users?per_page=20')->assertOk();
    $cursor = parse_url($first->json('links.next'), PHP_URL_QUERY);

    // When the real query of the first and the second page is captured
    $sqls = commentCaptureSql(function () use ($cursor) {
        test()->getJson('/api/v1/me/follows/users?per_page=20')->assertOk();
        test()->getJson('/api/v1/me/follows/users?per_page=20&'.$cursor)->assertOk();
    });
    $queries = array_values(array_filter($sqls, fn (string $q) => str_contains($q, 'from "user_follows"')));

    // Then both plans use the keyset index and never sort by hand
    expect($queries)->toHaveCount(2);
    foreach ($queries as $sql) {
        $plan = commentPlan($sql);
        expect($plan)->toContain('user_follows_follower_created_index')->not->toContain('Sort Key');
    }
});
