<?php

use App\Actions\Ranking\TodayRanking;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use App\Models\Vote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

const TODAY_URL = '/api/v1/today';
const TODAY_DATE = '2026-10-21';

function todayProduct(array $attributes = []): Product
{
    return Product::factory()->live(TODAY_DATE)->create($attributes);
}

function cookieNames($response): array
{
    return array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
}

it('orders by hot score, then votes, then id, and ranks from 1', function () {
    // Given four launches today: a tie on score broken by votes, then a tie on both broken by id
    $low = todayProduct(['hot_score' => 1.5, 'votes_count' => 9]);
    $tieFewVotes = todayProduct(['hot_score' => 4.0, 'votes_count' => 2]);
    $tieMoreVotes = todayProduct(['hot_score' => 4.0, 'votes_count' => 7]);
    [$sameA, $sameB] = [todayProduct(['hot_score' => 4.0, 'votes_count' => 7]), todayProduct(['hot_score' => 9.0, 'votes_count' => 0])];
    $sameIds = collect([$tieMoreVotes, $sameA])->pluck('id')->sort()->values()->all();

    // When the feed is read
    $response = $this->getJson(TODAY_URL);

    // Then the best score is first, ties follow votes then id, and ranks count up from 1
    $response->assertOk();
    expect($response->json('data.*.id'))->toBe([$sameB->id, ...$sameIds, $tieFewVotes->id, $low->id]);
    expect($response->json('data.*.rank'))->toBe([1, 2, 3, 4, 5]);
    expect($response->json('data.0.votes_count'))->toBe(0)->and($response->json('data.1.votes_count'))->toBe(7);
    expect($response->json('meta.date'))->toBe(TODAY_DATE)->and($response->json('meta.generated_at'))->toBe('2026-10-21T12:00:00+00:00');
    expect(array_keys($response->json()))->toBe(['data', 'meta']);
});

it('has the public card keys and nothing internal', function () {
    // Given a launch today by a maker with a private email
    $maker = User::factory()->create(['email' => 'secret@example.com']);
    todayProduct(['maker_id' => $maker->id]);

    // When a guest reads the feed
    $response = $this->getJson(TODAY_URL)->assertOk();

    // Then the card has the public keys, votes and rank, no has_voted for a guest, and no status, score or email
    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'slug', 'name', 'tagline', 'description', 'website_url', 'logo_url', 'launch_date', 'launched_at',
        'votes_count', 'rank', 'comments_count', 'maker', 'topics',
    ]);
    expect($response->getContent())->not->toContain('secret@example.com')->not->toContain('hot_score');
    $response->assertJsonMissingPath('data.0.has_voted')->assertJsonMissingPath('data.0.status');
});

it('returns at most 100 launches and drops the lowest', function () {
    // Given 101 launches today with distinct scores
    $products = collect(range(1, 101))->map(fn (int $score) => todayProduct(['hot_score' => $score]));

    // When the feed is read
    $response = $this->getJson(TODAY_URL)->assertOk();

    // Then 100 come back, ranked 1..100, and the lowest score is missing
    expect($response->json('data'))->toHaveCount(100);
    expect($response->json('data.*.rank'))->toBe(range(1, 100));
    expect($response->json('data.*.id'))->not->toContain($products->first()->id);
    expect($response->json('data.0.id'))->toBe($products->last()->id);
});

it('lists only visible launches of today', function (string $case) {
    // Given one product in a hidden or off-day state and one valid launch
    $valid = todayProduct();
    match ($case) {
        'yesterday' => Product::factory()->live('2026-10-20')->create(),
        'tomorrow scheduled' => Product::factory()->scheduled('2026-10-22')->create(),
        'scheduled for today' => Product::factory()->scheduled(TODAY_DATE)->create(),
        'taken down' => Product::factory()->takenDown()->create(['launch_date' => TODAY_DATE]),
        'pending' => Product::factory()->pending()->create(),
        'draft' => Product::factory()->complete()->create(),
        'soft deleted' => todayProduct(['deleted_at' => now()]),
        'deleted maker' => todayProduct(['maker_id' => User::factory()->create(['deleted_at' => now()])->id]),
    };

    // When the feed is read
    // Then only the valid launch appears
    expect($this->getJson(TODAY_URL)->assertOk()->json('data.*.id'))->toBe([$valid->id]);
})->with(['yesterday', 'tomorrow scheduled', 'scheduled for today', 'taken down', 'pending', 'draft', 'soft deleted', 'deleted maker']);

it('keeps the launch of a suspended maker, with the reduced maker card', function () {
    // Given a launch today by a suspended maker
    $product = todayProduct(['maker_id' => User::factory()->suspended()->create()->id]);

    // When the feed is read
    // Then it is listed and the maker card is reduced
    $response = $this->getJson(TODAY_URL)->assertOk();
    expect($response->json('data.*.id'))->toBe([$product->id]);
    $response->assertJsonPath('data.0.maker.is_suspended', true);
});

it('never shows a maker their own hidden product', function () {
    // Given a signed-in maker with a pending and a taken-down product and one live launch
    $maker = signedInMember();
    Product::factory()->pending()->create(['maker_id' => $maker->id]);
    Product::factory()->takenDown()->create(['maker_id' => $maker->id, 'launch_date' => TODAY_DATE]);
    $live = todayProduct(['maker_id' => $maker->id]);

    // When they read the feed
    // Then only the live one is there
    expect($this->getJson(TODAY_URL)->assertOk()->json('data.*.id'))->toBe([$live->id]);
});

it('is empty, not an error, when nothing launches today', function () {
    // Given a launch yesterday only
    Product::factory()->live('2026-10-20')->create();

    // When the feed is read
    // Then it is an empty list
    $this->getJson(TODAY_URL)->assertOk()->assertExactJson(['data' => [], 'meta' => ['date' => TODAY_DATE, 'generated_at' => '2026-10-21T12:00:00+00:00']]);
});

it('follows the UTC day boundary', function () {
    // Given a launch on the 21st and the clock one second before midnight
    $product = todayProduct();
    $this->travelTo(new DateTimeImmutable('2026-10-21 23:59:59 UTC'));
    $before = $this->getJson(TODAY_URL)->json('data.*.id');

    // When midnight passes
    $this->travelTo(new DateTimeImmutable('2026-10-22 00:00:00 UTC'));
    $after = $this->getJson(TODAY_URL);

    // Then the launch leaves the feed and the date moves on
    expect($before)->toBe([$product->id])->and($after->json('data'))->toBe([])->and($after->json('meta.date'))->toBe('2026-10-22');
});

it('tells a signed-in member which launches they voted for, and omits has_voted for guests', function () {
    // Given a member with an active vote, a withdrawn vote, a voided vote and no vote, and someone else's vote
    $member = User::factory()->create();
    [$voted, $withdrawn, $voided, $none, $others] = [todayProduct(['hot_score' => 5]), todayProduct(['hot_score' => 4]), todayProduct(['hot_score' => 3]), todayProduct(['hot_score' => 2]), todayProduct(['hot_score' => 1])];
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $voted->id]);
    Vote::factory()->flagged()->create(['user_id' => User::factory()->create()->id, 'product_id' => $none->id]);
    Vote::factory()->removed()->create(['user_id' => $member->id, 'product_id' => $withdrawn->id]);
    Vote::factory()->voided()->create(['user_id' => $member->id, 'product_id' => $voided->id]);
    Vote::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $others->id]);

    // When a guest and then the member read the feed
    $guest = $this->getJson(TODAY_URL)->assertOk();
    Sanctum::actingAs($member);
    $known = $this->getJson(TODAY_URL)->assertOk();

    // Then guests get no key, and the member gets true only for the active vote
    foreach ($guest->json('data') as $card) {
        expect($card)->not->toHaveKey('has_voted');
    }
    expect($known->json('data.*.has_voted'))->toBe([true, false, false, false, false]);
});

it('counts a flagged vote as voted', function () {
    // Given a member with a flagged vote
    $member = signedInMember();
    $product = todayProduct();
    Vote::factory()->flagged()->create(['user_id' => $member->id, 'product_id' => $product->id]);

    // When they read the feed
    // Then it shows as voted
    $this->getJson(TODAY_URL)->assertOk()->assertJsonPath('data.0.has_voted', true);
});

it('treats a suspended or deleted caller as a guest', function (string $state) {
    // Given an unavailable member who has a vote
    $member = $state === 'suspended' ? User::factory()->suspended()->create() : User::factory()->create(['deleted_at' => now()]);
    $product = todayProduct();
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    Sanctum::actingAs($member);

    // When they read the feed
    $response = $this->getJson(TODAY_URL);

    // Then it works and carries no has_voted
    $response->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.has_voted');
})->with(['suspended', 'deleted']);

it('knows a caller by bearer token without requiring one', function () {
    // Given a member with a token and a vote
    $member = User::factory()->create();
    $product = todayProduct();
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    $token = $member->createToken('t')->plainTextToken;

    // When the feed is read with the token, with a junk token, and without
    $withToken = $this->withHeader('Authorization', 'Bearer '.$token)->getJson(TODAY_URL);
    app('auth')->forgetGuards(); // only because the test app reuses one container across requests (php-fpm builds a fresh one per request)
    $junk = $this->withHeader('Authorization', 'Bearer 1|nonsense')->getJson(TODAY_URL);
    app('auth')->forgetGuards();
    $without = $this->withHeader('Authorization', '')->getJson(TODAY_URL);

    // Then the token shows has_voted, and the others are plain guests (never 401)
    $withToken->assertOk()->assertJsonPath('data.0.has_voted', true);
    $junk->assertOk()->assertJsonMissingPath('data.0.has_voted');
    $without->assertOk()->assertJsonMissingPath('data.0.has_voted');
});

it('does not run N+1 queries and finds votes in one query, whatever the number of launches', function () {
    // Given a member and a feed of 5 launches, all voted
    $member = User::factory()->create();
    $count = function () use ($member): int {
        Sanctum::actingAs($member);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->getJson(TODAY_URL)->assertOk();

        return count($queries);
    };
    foreach (todayProductBatch(5) as $product) {
        Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    }
    $small = $count();

    // When 45 more launches (with topics) are added and the feed is read again
    foreach (todayProductBatch(45) as $product) {
        Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    }
    $large = $count();

    // Then the query count is the same, and the vote lookup is a single query
    expect($large)->toBe($small);
    $votes = [];
    DB::listen(function ($query) use (&$votes) {
        if (str_contains($query->sql, 'from "votes"')) {
            $votes[] = $query->sql;
        }
    });
    $this->getJson(TODAY_URL)->assertOk();
    expect($votes)->toHaveCount(1);
});

function todayProductBatch(int $n): array
{
    return collect(range(1, $n))->map(function () {
        $product = todayProduct();
        $topic = Topic::factory()->create();
        DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);

        return $product;
    })->all();
}

it('uses the same number of queries for guests whatever the number of launches', function () {
    // Given 3 launches
    $queries = 0;
    todayProductBatch(3);
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $this->getJson(TODAY_URL)->assertOk();
    $small = $queries;

    // When 30 more are added
    todayProductBatch(30);
    $queries = 0;
    $this->getJson(TODAY_URL)->assertOk();

    // Then nothing grows
    expect($queries)->toBe($small);
});

it('is anonymous on a non-stateful origin: only the device cookie', function () {
    // Given a launch and a member
    todayProduct();
    $member = User::factory()->create();

    // When a guest and a member read the feed
    $guest = $this->getJson(TODAY_URL);
    Sanctum::actingAs($member);
    $known = $this->getJson(TODAY_URL);

    // Then (non-stateful origin) neither response sets a session or XSRF cookie
    expect(cookieNames($guest))->toBe(['lp_did'])->and(cookieNames($known))->toBe(['lp_did']);
});

it('ignores query parameters of every shape without an error', function (string $query) {
    // Given a launch today
    $product = todayProduct();

    // When hostile or stray parameters are sent
    $response = $this->getJson(TODAY_URL.'?'.$query);

    // Then they change nothing and never cause a 500
    $response->assertOk();
    expect($response->json('data.*.id'))->toBe([$product->id]);
})->with([
    'limit array' => ['limit[]='], 'limit object' => ['limit[a]=b'], 'empty date' => ['date='], 'date array' => ['date[]=2020-01-01'],
    'past date' => ['date=2020-01-01'], 'limit one' => ['limit=1'], 'limit zero' => ['limit=0'], 'cursor' => ['cursor=bnVsbA'],
]);

it('ignores a body on GET without an error', function () {
    // Given a launch today
    todayProduct();

    // When a stray JSON body of each shape is sent
    // Then the answer is still 200
    foreach ([['limit' => [1]], ['limit' => null], ['date' => ['a' => 'b']]] as $body) {
        $this->call('GET', TODAY_URL, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body))->assertOk();
    }
});

it('has an index that serves the ordering of the real query', function () {
    // Given the SQL the ranking really runs (captured, bindings substituted)
    $captured = null;
    DB::listen(function ($query) use (&$captured) {
        $captured ??= DB::getQueryGrammar()->substituteBindingsIntoRawSql($query->sql, $query->bindings);
    });
    app(TodayRanking::class)->products(CarbonImmutable::now('UTC'));

    // When its plan is read with sequential scans, bitmap scans and sorts disabled
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
    $plan = collect(DB::select('EXPLAIN '.$captured))->pluck('QUERY PLAN')->implode("\n");

    // Then Postgres uses products_launch_date_hot_score_index with the launch_date equality
    expect($captured)->toContain('order by "hot_score" desc, "votes_count" desc, "id" asc');
    expect($plan)->toContain('products_launch_date_hot_score_index')->toContain("launch_date = '2026-10-21'");
});

it('with a Sanctum stateful origin, a guest gets the framework session and XSRF cookies like on every API route', function () {
    // Given a launch today and a web client from the stateful SPA origin
    todayProduct();

    // When a guest reads the feed
    $response = spa()->getJson(TODAY_URL)->assertOk();

    // Then the session middleware ran (accepted for now): all three cookies, and nothing signed in
    expect(cookieNames($response))->toBe(['lp_did', 'XSRF-TOKEN', config('session.cookie')]);
    $response->assertJsonMissingPath('data.0.has_voted');
});

it('with a Sanctum stateful origin, the session cookie signs the caller in for has_voted', function () {
    // Given a member who voted, signed in through the real login flow from the SPA origin
    $member = User::factory()->create(['email' => 'amina@example.com']);
    $product = todayProduct();
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    $cookie = spa()->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'correct-horse-battery'])
        ->assertOk()->getCookie(config('session.cookie'), false);
    app('auth')->forgetGuards(); // only because the test app reuses one container across requests (php-fpm builds a fresh one per request)

    // When they read the feed with the session cookie
    $response = spa()->withCredentials()->withCookie($cookie->getName(), $cookie->getValue())->getJson(TODAY_URL);

    // Then they are recognised, and the cookies are the same three
    $response->assertOk()->assertJsonPath('data.0.has_voted', true);
    expect(cookieNames($response))->toBe(['lp_did', 'XSRF-TOKEN', config('session.cookie')]);
});

it('sends the same cache policy on both viewer-dependent reads: Vary always, no-store only for a known viewer', function () {
    // Given a launch today
    $product = todayProduct();
    $member = User::factory()->create();

    foreach ([TODAY_URL, "/api/v1/products/{$product->slug}"] as $url) {
        // When a guest and then a member read it
        app('auth')->forgetGuards();
        $guest = $this->getJson($url)->assertOk();
        Sanctum::actingAs($member);
        $known = $this->getJson($url)->assertOk();

        // Then both vary on the credentials, only the member's response is no-store
        foreach ([$guest, $known] as $response) {
            expect($response->headers->get('Vary'))->toContain('Authorization')->toContain('Cookie');
        }
        expect($guest->headers->get('Cache-Control'))->toBe('no-cache, private');
        expect($known->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});
