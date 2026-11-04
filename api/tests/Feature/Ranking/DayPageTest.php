<?php

use App\Actions\Ranking\FreezeDay;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use App\Models\Vote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));
afterEach(fn () => Product::flushEventListeners());

function pastDayProduct(string $day, array $attributes = []): Product
{
    return Product::factory()->live($day)->create($attributes);
}

it('serves a frozen day by the frozen rank and votes, not the live values', function () {
    // Given a frozen day of three products
    $first = pastDayProduct('2026-10-20', ['votes_count' => 9]);
    $second = pastDayProduct('2026-10-20', ['votes_count' => 4]);
    $third = pastDayProduct('2026-10-20', ['votes_count' => 1]);
    DB::table('product_hourly_stats')->insert([
        ['product_id' => $first->id, 'hour_start' => '2026-10-20 00:00:00+00', 'votes' => 9, 'views' => 0],
        ['product_id' => $second->id, 'hour_start' => '2026-10-20 00:00:00+00', 'votes' => 4, 'views' => 0],
        ['product_id' => $third->id, 'hour_start' => '2026-10-20 00:00:00+00', 'votes' => 1, 'views' => 0],
    ]);
    app(FreezeDay::class)->handle();

    // When the live values change and the page is read
    DB::table('products')->where('id', $third->id)->update(['hot_score' => 999, 'votes_count' => 500]);
    $response = $this->getJson('/api/v1/days/2026-10-20')->assertOk();

    // Then the frozen order, ranks and votes are served
    expect($response->json('data.*.id'))->toBe([$first->id, $second->id, $third->id]);
    expect($response->json('data.*.rank'))->toBe([1, 2, 3])->and($response->json('data.*.votes_count'))->toBe([9, 4, 1]);
    expect($response->json('meta'))->toBe(['date' => '2026-10-20', 'frozen' => true]);
    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'slug', 'name', 'tagline', 'description', 'website_url', 'logo_url', 'launch_date', 'launched_at',
        'votes_count', 'rank', 'comments_count', 'maker', 'topics',
    ]);
});

it('hides products taken down or whose maker was deleted after the freeze, keeping the frozen ranks', function () {
    // Given a frozen day of three, then one taken down and one with a deleted maker
    $a = pastDayProduct('2026-10-20');
    $b = pastDayProduct('2026-10-20');
    $c = pastDayProduct('2026-10-20');
    app(FreezeDay::class)->handle();
    $order = DB::table('daily_rankings')->orderBy('rank')->pluck('product_id')->all();
    DB::table('products')->where('id', $order[0])->update(['status' => 'taken_down', 'taken_down_at' => now(), 'takedown_reason' => 'x']);
    DB::table('users')->where('id', Product::query()->find($order[1])->maker_id)->update(['deleted_at' => now()]);

    // When the page is read
    $response = $this->getJson('/api/v1/days/2026-10-20')->assertOk();

    // Then only the third is listed, with its frozen rank
    expect($response->json('data.*.id'))->toBe([$order[2]])->and($response->json('data.0.rank'))->toBe(3);
});

it('computes a finished day that is not frozen yet with the freeze ordering and says so', function () {
    // Given an unfrozen day where the stored hot_score disagrees with the final score
    $stale = pastDayProduct('2026-10-20', ['hot_score' => 900, 'votes_count' => 1]);
    $real = pastDayProduct('2026-10-20', ['hot_score' => 0, 'votes_count' => 6]);
    $hidden = Product::factory()->takenDown()->create(['launch_date' => '2026-10-20']);
    DB::table('product_hourly_stats')->insert([
        ['product_id' => $stale->id, 'hour_start' => '2026-10-20 10:00:00+00', 'votes' => 1, 'views' => 0],
        ['product_id' => $real->id, 'hour_start' => '2026-10-20 10:00:00+00', 'votes' => 6, 'views' => 0],
    ]);

    // When the page is read, then the day is frozen and read again
    $before = $this->getJson('/api/v1/days/2026-10-20')->assertOk();
    app(FreezeDay::class)->handle();
    $after = $this->getJson('/api/v1/days/2026-10-20')->assertOk();

    // Then both agree on the order; only the flag differs; the taken-down one is absent
    expect($before->json('data.*.id'))->toBe([$real->id, $stale->id])->and($after->json('data.*.id'))->toBe([$real->id, $stale->id]);
    expect($before->json('meta.frozen'))->toBeFalse()->and($after->json('meta.frozen'))->toBeTrue();
    expect($before->json('data.*.rank'))->toBe([1, 2])->and($before->json('data.*.id'))->not->toContain($hidden->id);
});

it('answers today like /today with meta.frozen false', function () {
    // Given launches today
    pastDayProduct('2026-10-21', ['hot_score' => 2]);
    pastDayProduct('2026-10-21', ['hot_score' => 7]);

    // When both are read
    $today = $this->getJson('/api/v1/today')->assertOk();
    $day = $this->getJson('/api/v1/days/2026-10-21')->assertOk();

    // Then the data is the same
    expect($day->json('data'))->toBe($today->json('data'))->and($day->json('meta'))->toBe(['date' => '2026-10-21', 'frozen' => false]);
});

it('answers a future date with the uniform 404 body', function () {
    // Given a scheduled launch tomorrow
    Product::factory()->scheduled('2026-10-22')->create();

    // When the future day and a missing resource are read
    $future = $this->getJson('/api/v1/days/2026-10-22');
    $missing = $this->getJson('/api/v1/users/01ARZ3NDEKTSV4RRFFQ69G5FAV');

    // Then the bodies are the same
    $future->assertNotFound();
    expect($future->json())->toBe($missing->json());
});

it('is an empty list for a day without launches', function () {
    // Given nothing launched on the 1st or long ago
    // When those days are read
    // Then data is empty and the day is not frozen
    foreach (['2026-10-01', '1999-01-01'] as $date) {
        $this->getJson("/api/v1/days/{$date}")->assertOk()->assertExactJson(['data' => [], 'meta' => ['date' => $date, 'frozen' => false]]);
    }
});

it('rejects a wrong-shaped date with 422 on date and never 500', function (string $date) {
    // Given any wrong shape in the route segment
    // When the day is read
    $response = $this->getJson('/api/v1/days/'.$date);

    // Then it is a validation error on date
    $response->assertUnprocessable()->assertJsonValidationErrors('date');
})->with(['2026-02-31', '2026-2-3', 'abc', '20261001', '2026-10-01x', '2026-13-01', '0000-00-00', '2026-10-1', '%202026-10-01', '2026-10-01%00', '%00', str_repeat('1', 5000)]);

it('ignores a query-string or body date, using the route segment only', function (string $extra) {
    // Given a launch on the 20th
    $product = pastDayProduct('2026-10-20');

    // When hostile extras accompany the request
    $response = $this->getJson('/api/v1/days/2026-10-20?'.$extra);

    // Then the route segment wins
    $response->assertOk();
    expect($response->json('data.*.id'))->toBe([$product->id]);
})->with(['date[]=x', 'date[a]=b', 'date=', 'date=2020-01-01', 'cursor[]=1']);

it('treats an array, object or null JSON body as noise', function () {
    // Given a launch on the 20th
    pastDayProduct('2026-10-20');

    // When bodies of each shape are sent with the GET
    // Then the answer is 200
    foreach ([['date' => [1]], ['date' => null], ['date' => ['a' => 'b']]] as $body) {
        $this->call('GET', '/api/v1/days/2026-10-20', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body))->assertOk();
    }
});

it('tells a known caller which launches they voted for on a frozen day, with the shared cache policy', function () {
    // Given a frozen day, a member who voted for one and a guest read
    $member = User::factory()->create();
    $voted = pastDayProduct('2026-10-20', ['votes_count' => 1]);
    $other = pastDayProduct('2026-10-20', ['votes_count' => 0]);
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $voted->id]);
    DB::table('product_hourly_stats')->insert(['product_id' => $voted->id, 'hour_start' => '2026-10-20 01:00:00+00', 'votes' => 1, 'views' => 0]);
    app(FreezeDay::class)->handle();

    // When a guest and then the member read the page
    $guest = $this->getJson('/api/v1/days/2026-10-20')->assertOk();
    Sanctum::actingAs($member);
    $known = $this->getJson('/api/v1/days/2026-10-20')->assertOk();

    // Then only the member gets has_voted, and the cache headers are the shared policy
    $guest->assertJsonMissingPath('data.0.has_voted');
    expect($known->json('data.*.id'))->toBe([$voted->id, $other->id])->and($known->json('data.*.has_voted'))->toBe([true, false]);
    expect($guest->headers->get('Vary'))->toContain('Authorization')->toContain('Cookie');
    expect($guest->headers->get('Cache-Control'))->toBe('no-cache, private');
    expect($known->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

function pastDayBatch(int $n): void
{
    foreach (range(1, $n) as $_) {
        $product = pastDayProduct('2026-10-20');
        $topic = Topic::factory()->create();
        DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);
    }
}

it('uses the same number of queries whatever the number of launches, frozen or not', function (bool $frozen) {
    // Given a member and a small day
    $member = User::factory()->create();
    $count = function () use ($member): int {
        Sanctum::actingAs($member);
        test()->getJson('/api/v1/days/2026-10-20')->assertOk(); // warm-up: settings are cached after the first read
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        test()->getJson('/api/v1/days/2026-10-20')->assertOk();

        return $queries;
    };
    pastDayBatch(3);
    if ($frozen) {
        app(FreezeDay::class)->handle();
    }
    $small = $count();

    // When many more launches join the day
    if ($frozen) {
        // a frozen day cannot take more rows through the freeze, so insert ranks directly for the new products
        $rank = 3;
        foreach (range(1, 30) as $_) {
            $p = pastDayProduct('2026-10-20');
            DB::table('product_topics')->insert(['product_id' => $p->id, 'topic_id' => Topic::factory()->create()->id]);
            DB::table('daily_rankings')->insert(['launch_date' => '2026-10-20', 'product_id' => $p->id, 'rank' => ++$rank, 'votes_count' => 0, 'score' => 0, 'frozen_at' => now()]);
        }
    } else {
        pastDayBatch(30);
    }
    $large = $count();

    // Then the count is the same
    expect($large)->toBe($small);
})->with([false, true]);

it('keeps frozen ranks with gaps after a later takedown', function () {
    // Given a frozen day of three
    foreach ([3, 2, 1] as $votes) {
        $product = pastDayProduct('2026-10-19', ['votes_count' => $votes]);
        DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => '2026-10-19 10:00:00+00', 'votes' => $votes, 'views' => 0]);
    }
    app(FreezeDay::class)->handle();

    // When its best product is taken down afterwards
    DB::table('products')->where('votes_count', 3)->update(['status' => 'taken_down', 'taken_down_at' => now(), 'takedown_reason' => 'x']);
    $response = $this->getJson('/api/v1/days/2026-10-19')->assertOk();

    // Then the page keeps ranks 2 and 3 (a gap at 1)
    expect($response->json('meta.frozen'))->toBeTrue()->and($response->json('data.*.rank'))->toBe([2, 3]);
});

it('numbers an unfrozen page densely after a takedown', function () {
    // Given an unfrozen day of three whose best product was taken down
    foreach ([3, 2, 1] as $votes) {
        $product = pastDayProduct('2026-10-20', ['votes_count' => $votes]);
        DB::table('product_hourly_stats')->insert(['product_id' => $product->id, 'hour_start' => '2026-10-20 10:00:00+00', 'votes' => $votes, 'views' => 0]);
    }
    DB::table('products')->where('votes_count', 3)->update(['status' => 'taken_down', 'taken_down_at' => now(), 'takedown_reason' => 'x']);

    // When the page is read
    $response = $this->getJson('/api/v1/days/2026-10-20')->assertOk();

    // Then ranks are 1 and 2
    expect($response->json('meta.frozen'))->toBeFalse()->and($response->json('data.*.rank'))->toBe([1, 2])->and($response->json('data.*.votes_count'))->toBe([2, 1]);
});

it('shows only ranks up to 100 of a frozen day', function () {
    // Given a frozen day with 120 ranked products
    foreach (range(1, 120) as $_) {
        pastDayProduct('2026-10-20');
    }
    app(FreezeDay::class)->handle(CarbonImmutable::parse('2026-10-21 00:05:00 UTC'));

    // When the page is read
    // Then ranks 1..100 are served
    expect($this->getJson('/api/v1/days/2026-10-20')->assertOk()->json('data.*.rank'))->toBe(range(1, 100));
});

it('hydrates at most one page of products for a large unfrozen day', function () {
    // Given 120 launches on an unfrozen day, and a counter of the product models loaded
    foreach (range(1, 120) as $_) {
        pastDayProduct('2026-10-20');
    }
    $loaded = 0;
    Product::retrieved(function () use (&$loaded) {
        $loaded++;
    });

    // When the page is read
    $response = $this->getJson('/api/v1/days/2026-10-20')->assertOk();

    // Then 100 come back and no more than 100 models were built
    expect($response->json('data'))->toHaveCount(100)->and($loaded)->toBe(100);
});

it('returns at most 100 launches', function () {
    // Given 101 launches on an unfrozen day
    foreach (range(1, 101) as $_) {
        pastDayProduct('2026-10-20');
    }

    // When the page is read
    // Then 100 come back, ranked 1..100
    $response = $this->getJson('/api/v1/days/2026-10-20')->assertOk();
    expect($response->json('data.*.rank'))->toBe(range(1, 100));
});
