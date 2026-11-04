<?php

use App\Actions\Ranking\Leaderboard;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use App\Models\Vote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

const BOARD_URL = '/api/v1/leaderboards';
const BOARD_TODAY = '2026-10-21';

function boardProduct(int $daysAgo, int $votes = 0, array $attributes = []): Product
{
    return Product::factory()->live(CarbonImmutable::parse(BOARD_TODAY)->subDays($daysAgo)->toDateString())->create(['votes_count' => $votes] + $attributes);
}

function boardTopic(Product ...$products): Topic
{
    $topic = Topic::factory()->create();
    foreach ($products as $product) {
        DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);
    }

    return $topic;
}

function boardIds(string $query): array
{
    return test()->getJson(BOARD_URL.'?'.$query)->assertOk()->json('data.*.id');
}

function boardBatch(int $n): array
{
    return collect(range(1, $n))->map(function () {
        $product = boardProduct(1, 3);
        boardTopic($product);

        return $product;
    })->all();
}

it('orders by votes, then newest launch day, then id, and ranks from 1', function () {
    // Given launches with a tie on votes broken by launch day, and a tie on both broken by id
    $few = boardProduct(0, 2);
    $oldTop = boardProduct(3, 9);
    $newTop = boardProduct(1, 9);
    $sameA = boardProduct(2, 5);
    $sameB = boardProduct(2, 5);
    $sameIds = collect([$sameA, $sameB])->pluck('id')->sort()->values()->all();

    // When the week board is read
    $response = $this->getJson(BOARD_URL.'?period=week')->assertOk();

    // Then votes win, then the newer launch day, then the lower id; ranks count up from 1
    expect($response->json('data.*.id'))->toBe([$newTop->id, $oldTop->id, ...$sameIds, $few->id]);
    expect($response->json('data.*.rank'))->toBe([1, 2, 3, 4, 5]);
    expect($response->json('data.0.votes_count'))->toBe(9);
    expect(array_keys($response->json()))->toBe(['data', 'meta']);
    expect($response->json('meta'))->toBe(['period' => 'week', 'topic' => null, 'generated_at' => '2026-10-21T12:00:00+00:00']);
});

it('has the public card keys and nothing internal', function () {
    // Given a launch by a maker with a private email
    boardProduct(0, 1, ['maker_id' => User::factory()->create(['email' => 'secret@example.com'])->id]);

    // When a guest reads the board
    $response = $this->getJson(BOARD_URL.'?period=all')->assertOk();

    // Then the card has the public keys, votes and rank, and nothing internal
    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'slug', 'name', 'tagline', 'description', 'website_url', 'logo_url', 'launch_date', 'launched_at',
        'votes_count', 'rank', 'comments_count', 'maker', 'topics',
    ]);
    expect($response->getContent())->not->toContain('secret@example.com')->not->toContain('hot_score');
});

it('applies the window boundaries of each period', function (string $period, int $inDay, int $outDay) {
    // Given launches on the edge of the window, just outside it, today and very old
    $edgeIn = boardProduct($inDay, 5);
    $edgeOut = boardProduct($outDay, 6);
    $today = boardProduct(0, 4);
    $old = boardProduct(400, 3);

    // When the board is read
    $ids = boardIds("period={$period}");

    // Then the inside edge and today are in; the outside edge only for all time; the old one only for all time
    expect($ids)->toContain($edgeIn->id, $today->id);
    if ($period === 'all') {
        expect($ids)->toBe([$edgeOut->id, $edgeIn->id, $today->id, $old->id]);
    } else {
        expect($ids)->not->toContain($edgeOut->id)->not->toContain($old->id);
    }
})->with([
    'week' => ['week', 6, 7],
    'month' => ['month', 29, 30],
    'all' => ['all', 6, 7],
]);

it('filters by topic with every period and lists only that topic', function (string $period) {
    // Given products in two topics and one in none, spread over time
    $inWeek = boardProduct(2, 5);
    $inMonth = boardProduct(20, 4);
    $old = boardProduct(100, 3);
    $other = boardProduct(1, 9);
    $none = boardProduct(0, 8);
    $topic = boardTopic($inWeek, $inMonth, $old);
    boardTopic($other);

    // When the topic board is read
    $response = $this->getJson(BOARD_URL."?period={$period}&topic={$topic->slug}")->assertOk();

    // Then only the topic's products inside the window come back, with the topic in meta
    $expected = ['week' => [$inWeek], 'month' => [$inWeek, $inMonth], 'all' => [$inWeek, $inMonth, $old]][$period];
    expect($response->json('data.*.id'))->toBe(array_map(fn (Product $p) => $p->id, $expected));
    expect($response->json('meta.topic'))->toBe($topic->slug)->and($response->json('meta.period'))->toBe($period);
    expect($response->json('data.*.id'))->not->toContain($other->id)->not->toContain($none->id);
})->with(['week', 'month', 'all']);

it('rejects an unknown topic on the topic field', function () {
    // Given no such topic
    // When it is asked for
    // Then it is a 422 on topic
    $this->getJson(BOARD_URL.'?period=all&topic=no-such-topic')->assertStatus(422)->assertJsonValidationErrors('topic');
});

it('rejects invalid period and topic shapes with 422, never 500', function (string $query, string $field) {
    // Given a launch and a topic
    boardProduct(0, 1);
    Topic::factory()->create(['slug' => 'ai']);

    // When a hostile or malformed parameter is sent
    $response = $this->getJson(BOARD_URL.'?'.$query);

    // Then it is a 422 naming the field
    $response->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'period missing' => ['', 'period'],
    'period year' => ['period=year', 'period'],
    'period empty' => ['period=', 'period'],
    'period array' => ['period[]=week', 'period'],
    'period object' => ['period[a]=week', 'period'],
    'period int' => ['period=1', 'period'],
    'period bool' => ['period=true', 'period'],
    'period missing with topic' => ['topic=ai', 'period'],
    'period uppercase' => ['period=WEEK', 'period'],
    'topic array' => ['period=all&topic[]=ai', 'topic'],
    'topic object' => ['period=all&topic[a]=ai', 'topic'],
    'topic empty' => ['period=all&topic=', 'topic'],
    'topic int' => ['period=all&topic=7', 'topic'],
    'topic bool' => ['period=all&topic=true', 'topic'],
    'topic uppercase' => ['period=all&topic=AI', 'topic'],
    'topic with slash' => ['period=all&topic=a/b', 'topic'],
    'topic too long' => ['period=all&topic='.'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'topic'],
    'topic max length but unknown' => ['period=all&topic='.'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'topic'],
]);

it('rejects a JSON body array/object/null for the parameters too, since only the query counts', function (mixed $period) {
    // Given a body that carries the parameters in hostile shapes and no query
    // When it is sent
    $response = $this->call('GET', BOARD_URL, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['period' => $period, 'topic' => $period]));

    // Then the body is ignored: the missing query period is a 422, not a 500
    $response->assertStatus(422)->assertJsonValidationErrors('period');
})->with([[['week']], [['a' => 'b']], [null], [true], [1], ['']]);

it('ignores a body when the query is valid', function () {
    // Given a launch
    $product = boardProduct(0, 1);

    // When a valid query comes with a hostile body
    $response = $this->call('GET', BOARD_URL.'?period=week', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode(['period' => ['x'], 'topic' => ['y']]));

    // Then the query wins and the body changes nothing
    $response->assertOk();
    expect($response->json('data.*.id'))->toBe([$product->id])->and($response->json('meta.topic'))->toBeNull();
});

it('returns at most 50 and drops the lowest', function () {
    // Given 51 launches with distinct votes
    $products = collect(range(1, 51))->map(fn (int $votes) => boardProduct(0, $votes));

    // When the board is read
    $response = $this->getJson(BOARD_URL.'?period=all')->assertOk();

    // Then 50 come back, ranked 1..50, without the lowest
    expect($response->json('data'))->toHaveCount(50);
    expect($response->json('data.*.rank'))->toBe(range(1, 50));
    expect($response->json('data.*.id'))->not->toContain($products->first()->id);
    expect($response->json('data.0.id'))->toBe($products->last()->id);
});

it('lists only visible launches', function (string $case) {
    // Given one product in a hidden state and one valid launch
    $valid = boardProduct(1, 1);
    match ($case) {
        'tomorrow scheduled' => Product::factory()->scheduled('2026-10-22')->create(['votes_count' => 50]),
        'scheduled for today' => Product::factory()->scheduled(BOARD_TODAY)->create(['votes_count' => 50]),
        'taken down' => Product::factory()->takenDown()->create(['launch_date' => BOARD_TODAY, 'votes_count' => 50]),
        'pending' => Product::factory()->pending()->create(['votes_count' => 50]),
        'draft' => Product::factory()->complete()->create(['votes_count' => 50]),
        'soft deleted' => boardProduct(0, 50, ['deleted_at' => now()]),
        'deleted maker' => boardProduct(0, 50, ['maker_id' => User::factory()->create(['deleted_at' => now()])->id]),
    };

    // When the board is read
    // Then only the valid launch appears
    expect(boardIds('period=all'))->toBe([$valid->id]);
})->with(['tomorrow scheduled', 'scheduled for today', 'taken down', 'pending', 'draft', 'soft deleted', 'deleted maker']);

it('keeps the launch of a suspended maker, with the reduced maker card', function () {
    // Given a launch by a suspended maker
    $product = boardProduct(0, 1, ['maker_id' => User::factory()->suspended()->create()->id]);

    // When the board is read
    $response = $this->getJson(BOARD_URL.'?period=week')->assertOk();

    // Then it is listed with the reduced maker card
    expect($response->json('data.*.id'))->toBe([$product->id]);
    $response->assertJsonPath('data.0.maker.is_suspended', true);
});

it('is an empty list, not an error, when nothing qualifies', function () {
    // Given only an old launch
    boardProduct(100, 1);

    // When the week board is read
    // Then it is empty
    $this->getJson(BOARD_URL.'?period=week')->assertOk()->assertExactJson(['data' => [], 'meta' => ['period' => 'week', 'topic' => null, 'generated_at' => '2026-10-21T12:00:00+00:00']]);
});

it('moves its window with the UTC day', function () {
    // Given a launch six days before the 21st
    $product = boardProduct(6, 1);
    $before = boardIds('period=week');

    // When midnight passes
    $this->travelTo(new DateTimeImmutable('2026-10-22 00:00:00 UTC'));
    $after = boardIds('period=week');

    // Then the launch has left the week
    expect($before)->toBe([$product->id])->and($after)->toBe([]);
});

it('tells a signed-in member which launches they voted for, and omits has_voted for guests', function () {
    // Given a member with an active vote, a withdrawn vote and no vote
    $member = User::factory()->create();
    [$voted, $withdrawn, $none] = [boardProduct(0, 5), boardProduct(0, 4), boardProduct(0, 3)];
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $voted->id]);
    Vote::factory()->removed()->create(['user_id' => $member->id, 'product_id' => $withdrawn->id]);

    // When a guest and then the member read the board
    $guest = $this->getJson(BOARD_URL.'?period=all')->assertOk();
    Sanctum::actingAs($member);
    $known = $this->getJson(BOARD_URL.'?period=all')->assertOk();

    // Then guests get no key; the member gets true only for the active vote
    foreach ($guest->json('data') as $card) {
        expect($card)->not->toHaveKey('has_voted');
    }
    expect($known->json('data.*.has_voted'))->toBe([true, false, false]);
});

it('sends Vary always and no-store only for a known viewer', function () {
    // Given a launch and a member
    boardProduct(0, 1);
    $member = User::factory()->create();

    // When a guest and then the member read the board
    $guest = $this->getJson(BOARD_URL.'?period=all')->assertOk();
    Sanctum::actingAs($member);
    $known = $this->getJson(BOARD_URL.'?period=all')->assertOk();

    // Then both vary on the credentials; only the member's is no-store
    foreach ([$guest, $known] as $response) {
        expect($response->headers->get('Vary'))->toContain('Authorization')->toContain('Cookie');
    }
    expect($guest->headers->get('Cache-Control'))->toBe('no-cache, private');
    expect($known->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('does not run N+1 queries, whatever the number of launches', function () {
    // Given a member who voted for 5 launches with topics
    $member = User::factory()->create();
    $count = function () use ($member): int {
        Sanctum::actingAs($member);
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $this->getJson(BOARD_URL.'?period=all')->assertOk();

        return count($queries);
    };
    foreach (boardBatch(5) as $product) {
        Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    }
    $small = $count();

    // When 45 more are added
    foreach (boardBatch(45) as $product) {
        Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    }
    $large = $count();

    // Then the query count is the same
    expect($large)->toBe($small);
});

it('uses the same number of queries for guests with a topic, whatever the number of launches', function () {
    // Given 5 launches in one topic
    $queries = 0;
    $products = boardBatch(5);
    $topic = boardTopic(...$products);
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $this->getJson(BOARD_URL.'?period=all&topic='.$topic->slug)->assertOk();
    $small = $queries;

    // When 45 more are added to the topic
    boardTopic(...boardBatch(45));
    $big = Topic::query()->latest('id')->first();
    $queries = 0;
    $this->getJson(BOARD_URL.'?period=all&topic='.$big->slug)->assertOk();

    // Then nothing grows
    expect($queries)->toBe($small);
});

it('is anonymous on a non-stateful origin: only the device cookie', function () {
    // Given a launch and a member
    boardProduct(0, 1);
    $member = User::factory()->create();

    // When a guest and a member read the board
    $guest = $this->getJson(BOARD_URL.'?period=all');
    Sanctum::actingAs($member);
    $known = $this->getJson(BOARD_URL.'?period=all');

    // Then neither response sets a session or XSRF cookie
    expect(array_map(fn ($c) => $c->getName(), $guest->headers->getCookies()))->toBe(['lp_did']);
    expect(array_map(fn ($c) => $c->getName(), $known->headers->getCookies()))->toBe(['lp_did']);
});

it('returns the same order from the card query and the lean query on tied data', function (string $period) {
    // Given many ties on votes and on launch day
    foreach (range(1, 6) as $i) {
        boardProduct($i % 3, 4);
        boardProduct($i % 3, 4);
        boardProduct(0, 7);
    }
    $now = CarbonImmutable::now('UTC');

    // When both variants are read
    $cards = app(Leaderboard::class)->products($period, null, $now)->modelKeys();
    $lean = app(Leaderboard::class)->ranking($period, null, $now);

    // Then the ids agree, and the lean variant carries no relations
    expect($lean->modelKeys())->toBe($cards);
    expect($lean->first()->relationLoaded('maker'))->toBeFalse();
    expect($lean->first()->getAttributes())->toHaveKeys(['id', 'votes_count'])->and(array_keys($lean->first()->getAttributes()))->toBe(['id', 'votes_count']);
})->with(['week', 'month', 'all']);

dataset('board_plans', [
    'all' => ['all', false],
    'month' => ['month', false],
    'week' => ['week', false],
    'week with topic' => ['week', true],
    'all with topic' => ['all', true],
]);

it('serves the ordering of the real query from the partial leaderboard index', function (string $period, bool $withTopic) {
    // Given a non-empty, analysed table (some products in a topic) so the planner has statistics
    $maker = User::factory()->create();
    $topic = Topic::factory()->create();
    foreach (range(1, 60) as $i) {
        $product = boardProduct($i % 5, $i % 11, ['maker_id' => $maker->id]);
        if ($i % 3 === 0) {
            DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);
        }
    }
    DB::statement('ANALYZE products');
    DB::statement('ANALYZE product_topics');

    // And the SQL the Action really runs (captured, bindings substituted)
    $captured = null;
    DB::listen(function ($query) use (&$captured) {
        $captured ??= DB::getQueryGrammar()->substituteBindingsIntoRawSql($query->sql, $query->bindings);
    });
    app(Leaderboard::class)->products($period, $withTopic ? $topic->slug : null, CarbonImmutable::now('UTC'));

    // When its plan is read with sequential scans, bitmap scans and sorts disabled
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
    $plan = collect(DB::select('EXPLAIN '.$captured))->pluck('QUERY PLAN')->implode("\n");

    // Then Postgres walks products_live_votes_launch_date_id_index with no separate sort. With a topic the filter is
    // a semi-join probed per product while walking the same index (measured; it is not a topic-first plan).
    expect($captured)->toContain('order by "votes_count" desc, "launch_date" desc, "id" asc');
    expect($plan)->toContain('products_live_votes_launch_date_id_index')->not->toContain('Sort Key');
})->with('board_plans');

it('lists a product in two topics once when filtered by one of them', function () {
    // Given a product in topics A and B
    $product = boardProduct(0, 3);
    $a = boardTopic($product);
    boardTopic($product);

    // When the board is filtered by A
    $response = $this->getJson(BOARD_URL.'?period=all&topic='.$a->slug)->assertOk();

    // Then it appears exactly once
    expect($response->json('data.*.id'))->toBe([$product->id]);
});

it('returns the same order from the lean query with a topic as from the card query', function (string $period) {
    // Given tied products in a topic and some outside it
    $in = collect(range(1, 8))->map(fn (int $i) => boardProduct($i % 3, 4));
    $topic = boardTopic(...$in->all());
    boardProduct(0, 9);
    $now = CarbonImmutable::now('UTC');

    // When both variants are read with the topic
    $cards = app(Leaderboard::class)->products($period, $topic->slug, $now)->modelKeys();
    $lean = app(Leaderboard::class)->ranking($period, $topic->slug, $now)->modelKeys();

    // Then they agree and hold only the topic's products
    expect($lean)->toBe($cards)->and($cards)->toHaveCount(8);
})->with(['week', 'month', 'all']);

it('answers an existing topic without products with an empty list', function () {
    // Given a topic nobody uses
    $topic = Topic::factory()->create();
    boardProduct(0, 1);

    // When its board is read
    // Then it is empty, not an error
    $this->getJson(BOARD_URL.'?period=all&topic='.$topic->slug)->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.topic', $topic->slug);
});
