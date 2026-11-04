<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('shows a visible product to anyone, with public fields only', function () {
    // Given a live product with a link, a topic and a maker who has a private email
    $maker = User::factory()->create(['handle' => 'amina_r', 'name' => 'Amina Rahman', 'email' => 'secret@example.com']);
    $product = Product::factory()->live()->create(['maker_id' => $maker->id, 'slug' => 'cool-app', 'name' => 'Cool App']);
    $product->links()->create(['kind' => 'github', 'url' => 'https://github.com/a/b', 'position' => 0]);

    // When an anonymous visitor opens it (the slug lookup ignores case)
    $response = $this->getJson('/api/v1/products/Cool-App');

    // Then they see the public keys, the maker's public profile card, and nothing internal
    $response->assertOk()->assertJsonPath('data.slug', 'cool-app')->assertJsonPath('data.maker.handle', 'amina_r')
        ->assertJsonPath('data.links.0.kind', 'github')->assertJsonCount(1, 'data.screenshots')->assertJsonCount(1, 'data.topics');
    expect(array_keys($response->json('data')))->toBe([
        'id', 'slug', 'name', 'tagline', 'description', 'website_url', 'logo_url', 'launch_date', 'launched_at',
        'votes_count', 'comments_count', 'rank', 'rank_date', 'maker', 'topics', 'links', 'screenshots',
    ]);
    expect(array_keys($response->json('data.maker')))->toBe(['id', 'handle', 'name', 'headline', 'avatar_url', 'links', 'is_suspended', 'joined_at', 'followers_count']);
    expect($response->getContent())->not->toContain('secret@example.com');
    $response->assertJsonMissingPath('data.status')->assertJsonMissingPath('data.takedown_reason')->assertJsonMissingPath('data.maker.email');
});

it('answers every non-visible case with the identical 404 body', function (string $case) {
    // Given a product in some hidden state
    $product = match ($case) {
        'draft' => Product::factory()->complete()->create(),
        'pending' => Product::factory()->pending()->create(),
        'approved' => Product::factory()->approved()->create(),
        'scheduled' => Product::factory()->scheduled()->create(),
        'rejected' => Product::factory()->rejected()->create(),
        'changes requested' => Product::factory()->changesRequested()->create(),
        'taken down' => Product::factory()->takenDown()->create(),
        'live but launching tomorrow' => Product::factory()->live(now('UTC')->addDay()->toDateString())->create(),
        'soft deleted' => Product::factory()->live()->create(['deleted_at' => now()]),
        'unknown' => new Product(['slug' => 'no-such-product']),
    };

    // When the public endpoint is asked for it
    $response = $this->getJson('/api/v1/products/'.$product->slug);

    // Then the answer cannot be told apart from "no such product"
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
})->with(['draft', 'pending', 'approved', 'scheduled', 'rejected', 'changes requested', 'taken down', 'live but launching tomorrow', 'soft deleted', 'unknown']);

it('does not show a maker their own hidden product through the public route', function () {
    // Given a signed-in maker with a pending product
    $maker = signedInMember();
    $product = Product::factory()->pending()->create(['maker_id' => $maker->id]);

    // When they open it on the public route
    // Then it is still a 404 (founders use /me/products/{id})
    $this->getJson("/api/v1/products/{$product->slug}")->assertNotFound();
});

it('follows the launch day at the UTC boundary and a take-down at once', function () {
    // Given a live product launching on the 21st and the clock one second before midnight UTC
    $product = Product::factory()->live('2026-10-21')->create(['slug' => 'midnight-app']);
    $this->travelTo(new DateTimeImmutable('2026-10-20 23:59:59 UTC'));

    // When it is requested before and at midnight
    $before = $this->getJson('/api/v1/products/midnight-app');
    $this->travelTo(new DateTimeImmutable('2026-10-21 00:00:00 UTC'));
    $at = $this->getJson('/api/v1/products/midnight-app');

    // Then it appears at midnight, and disappears the moment it is taken down
    $before->assertNotFound();
    $at->assertOk();
    $product->forceFill(['status' => ProductStatus::TakenDown, 'taken_down_at' => now()])->save();
    $this->getJson('/api/v1/products/midnight-app')->assertNotFound();
});

it('answers malformed slugs with 404 and never reaches the database with them', function (string $slug) {
    // Given any visitor
    // When the slug cannot be a slug (NUL byte, too long, uppercase with spaces, unicode, SQL)
    $response = $this->getJson('/api/v1/products/'.$slug);

    // Then it is the plain 404, not a database error
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
})->with(['null byte' => 'a%00b', 'too long' => str_repeat('a', 300), 'spaces' => 'a%20b%20c', 'unicode' => '%E0%A6%AC%E0%A6%BE', 'sql' => "x'%20OR%201=1--", 'short' => 'ab']);

it('keeps showing a product whose maker was later suspended, flagged on the maker card', function () {
    // Given a live product whose maker is now suspended
    $maker = User::factory()->suspended()->create();
    $product = Product::factory()->live()->create(['maker_id' => $maker->id]);

    // When it is opened
    $response = $this->getJson("/api/v1/products/{$product->slug}");

    // Then it is still public (moderation uses take-down) and the maker card is reduced
    $response->assertOk()->assertJsonPath('data.maker.is_suspended', true)->assertJsonPath('data.maker.headline', null);
});

it('is not marked no-store (public content)', function () {
    // Given a live product
    $product = Product::factory()->live()->create();

    // When it is read
    // Then the response is not private
    expect($this->getJson("/api/v1/products/{$product->slug}")->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('does not run N+1 queries', function () {
    // Given a live product with three topics
    $product = Product::factory()->live()->create();
    foreach (Topic::factory()->count(2)->create() as $topic) {
        DB::table('product_topics')->insert(['product_id' => $product->id, 'topic_id' => $topic->id]);
    }
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    // When it is read
    $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

    // Then a handful of queries serve it (product, maker, links of the maker, topics, links, screenshots)
    expect($queries)->toBeLessThanOrEqual(8);
});

it('hides the live product of a deleted maker, because the maker card cannot be rendered', function () {
    // Given a live product whose maker deleted their account
    $maker = User::factory()->create(['deleted_at' => now()]);
    $product = Product::factory()->live()->create(['maker_id' => $maker->id]);

    // When anyone opens it
    // Then it is the plain 404, while visible() itself (status and launch day) still includes it
    $this->getJson("/api/v1/products/{$product->slug}")->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect(Product::visible()->whereKey($product->id)->exists())->toBeTrue();
});

it('is anonymous: sets no session or CSRF cookie, and a token or cookie sent anyway changes nothing', function () {
    // Given a maker with a hidden (pending) product and a live one
    $maker = signedInMember();
    $hidden = Product::factory()->pending()->create(['maker_id' => $maker->id]);
    $live = Product::factory()->live()->create(['maker_id' => $maker->id]);
    $token = $maker->createToken('t')->plainTextToken;

    // When the routes are read with a bearer token and a stray session cookie
    $hiddenResponse = $this->withHeader('Authorization', 'Bearer '.$token)->withUnencryptedCookie('laravel_session', 'x')->getJson("/api/v1/products/{$hidden->slug}");
    $liveResponse = $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/v1/products/{$live->slug}");

    // Then hidden stays hidden, the live one is the same, and only the global device-id cookie is set (no session, no XSRF)
    $hiddenResponse->assertNotFound();
    $liveResponse->assertOk();
    $cookieNames = fn ($response) => array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
    expect($cookieNames($liveResponse))->toBe(['lp_did'])->and($cookieNames($hiddenResponse))->toBe(['lp_did']);
});

it('does not fold Unicode look-alikes onto an ASCII slug', function () {
    // Given a live product whose slug contains a k
    Product::factory()->live()->create(['slug' => 'kite-app']);

    // When the slug is asked for with the Kelvin sign (U+212A), which Unicode lowercases to k
    $response = $this->getJson('/api/v1/products/'.rawurlencode("\u{212A}ite-app"));

    // Then it is not the same product
    $response->assertNotFound();
});

it('shows the vote count to everyone and has_voted only to a known caller about themselves', function () {
    // Given a live product with two active votes, one of them the member's, plus a withdrawn one
    $product = Product::factory()->live()->create(['votes_count' => 2]);
    $member = User::factory()->create();
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    Vote::factory()->create(['product_id' => $product->id]);
    $other = User::factory()->create();
    Vote::factory()->removed()->create(['user_id' => $other->id, 'product_id' => $product->id]);

    // When a guest, the voter and the member who withdrew read it
    $guest = $this->getJson("/api/v1/products/{$product->slug}");
    Sanctum::actingAs($member);
    $voter = $this->getJson("/api/v1/products/{$product->slug}");
    app('auth')->forgetGuards(); // only because the test app reuses one container across requests (php-fpm builds a fresh one per request)
    Sanctum::actingAs($other);
    $withdrawn = $this->getJson("/api/v1/products/{$product->slug}");

    // Then everyone sees the count, guests get no has_voted, and the flag is true or false for the caller
    $guest->assertOk()->assertJsonPath('data.votes_count', 2)->assertJsonMissingPath('data.has_voted');
    $voter->assertOk()->assertJsonPath('data.votes_count', 2)->assertJsonPath('data.has_voted', true);
    $withdrawn->assertOk()->assertJsonPath('data.has_voted', false);
    $voter->assertJsonMissingPath('data.status')->assertJsonMissingPath('data.maker.email');
});

it('treats a suspended or deleted caller as a guest on the single read', function (string $state) {
    // Given an unavailable member who voted
    $product = Product::factory()->live()->create();
    $member = $state === 'suspended' ? User::factory()->suspended()->create() : User::factory()->create(['deleted_at' => now()]);
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    Sanctum::actingAs($member);

    // When they open the product
    $response = $this->getJson("/api/v1/products/{$product->slug}");

    // Then it works, carries no has_voted, and is not marked as made for a known viewer
    $response->assertOk()->assertJsonMissingPath('data.has_voted');
    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
})->with(['suspended', 'deleted']);

it('with a Sanctum stateful origin, a guest gets the framework session and XSRF cookies', function () {
    // Given a live product and a web client from the stateful SPA origin
    $product = Product::factory()->live()->create();

    // When a guest opens it
    $response = spa()->getJson("/api/v1/products/{$product->slug}")->assertOk();

    // Then the session middleware ran (accepted for now) and nobody is signed in
    expect(array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies()))->toBe(['lp_did', 'XSRF-TOKEN', config('session.cookie')]);
    $response->assertJsonMissingPath('data.has_voted');
});

it('shows comments_count from the column, default 0, on the single read and the ranked cards', function () {
    // Given a product launching today with 7 comments counted and one without
    $this->travelTo(new DateTimeImmutable('2026-10-20 12:00:00 UTC'));
    $busy = Product::factory()->live(now('UTC')->toDateString())->create(['comments_count' => 7]);
    $quiet = Product::factory()->live(now('UTC')->toDateString())->create();

    // When a guest reads them one by one and through the today feed
    $single = $this->getJson("/api/v1/products/{$busy->slug}");
    $zero = $this->getJson("/api/v1/products/{$quiet->slug}");
    $today = collect($this->getJson('/api/v1/today')->json('data'))->pluck('comments_count', 'id');

    // Then the count is an integer from the column
    $single->assertOk()->assertJsonPath('data.comments_count', 7);
    $zero->assertOk()->assertJsonPath('data.comments_count', 0);
    expect($today[$busy->id])->toBe(7)->and($today[$quiet->id])->toBe(0);
});

it('adds comments_count and votes_count to the user products list cards', function () {
    // Given a maker with a launched product
    $product = Product::factory()->live()->create(['comments_count' => 3]);

    // When the maker's product list is read
    $response = $this->getJson("/api/v1/users/{$product->maker->handle}/products");

    // Then the card carries both counters
    $response->assertOk()->assertJsonPath('data.0.comments_count', 3)->assertJsonPath('data.0.votes_count', 0);
});

it('shows the live rank of a product launched today and its rank date', function () {
    // Given three live products launched today with different vote counts
    $low = Product::factory()->live(now('UTC')->toDateString())->create(['votes_count' => 1, 'hot_score' => 1]);
    $top = Product::factory()->live(now('UTC')->toDateString())->create(['votes_count' => 9, 'hot_score' => 9]);
    $mid = Product::factory()->live(now('UTC')->toDateString())->create(['votes_count' => 5, 'hot_score' => 5]);

    // When each is read
    // Then the live position and today's date are given
    foreach ([[$top, 1], [$mid, 2], [$low, 3]] as [$product, $rank]) {
        $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->assertJsonPath('data.rank', $rank)->assertJsonPath('data.rank_date', now('UTC')->toDateString());
    }
});

it('shows the frozen rank of a past launch day, and null when that day has no frozen row', function () {
    // Given a product launched three days ago with a frozen rank, and one launched two days ago without
    $day = now('UTC')->subDays(3)->toDateString();
    $frozen = Product::factory()->live($day)->create();
    DB::table('daily_rankings')->insert(['launch_date' => $day, 'product_id' => $frozen->id, 'rank' => 4, 'votes_count' => 2, 'score' => 1.5, 'frozen_at' => now()]);
    $unfrozen = Product::factory()->live(now('UTC')->subDays(2)->toDateString())->create();

    // When both are read
    // Then the first has its frozen rank and day, the second has nulls
    $this->getJson("/api/v1/products/{$frozen->slug}")->assertOk()->assertJsonPath('data.rank', 4)->assertJsonPath('data.rank_date', $day);
    $this->getJson("/api/v1/products/{$unfrozen->slug}")->assertOk()->assertJsonPath('data.rank', null)->assertJsonPath('data.rank_date', null);
});

it('adds the maker follower count, and is_following only for a known viewer', function () {
    // Given a product whose maker has two followers, one of them the viewer
    $maker = User::factory()->create();
    $product = Product::factory()->live()->create(['maker_id' => $maker->id]);
    $viewer = User::factory()->create();
    foreach ([$viewer, User::factory()->create()] as $follower) {
        DB::table('user_follows')->insert(['follower_id' => $follower->id, 'followed_id' => $maker->id, 'created_at' => now()]);
    }

    // When a guest and the viewer read it
    $guest = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();
    Sanctum::actingAs($viewer);
    $known = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

    // Then both see the count, only the viewer sees the flag, and no following_count or products_count leaks onto the card
    expect($guest->json('data.maker.followers_count'))->toBe(2)->and($guest->json('data.maker'))->not->toHaveKey('is_following')->not->toHaveKey('following_count')->not->toHaveKey('products_count');
    expect($known->json('data.maker.is_following'))->toBeTrue();
});
