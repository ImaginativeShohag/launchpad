<?php

use App\Enums\Role;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    antiCheatFreeze();
    signedInStaff(Role::Reviewer);
});

it('lists the votes with the voter, status, fingerprints and the pinned keys', function () {
    // Given a counted, a flagged, a withdrawn and a voided vote on one product
    $product = antiCheatLiveProduct();
    $voter = antiCheatSetScore(User::factory()->create(['handle' => 'voter-one']), 55);
    $counted = antiCheatVote($voter, $product, ['device_hash' => antiCheatHash('dev'), 'ip_hash' => antiCheatHash('ip')]);
    $flagged = antiCheatVote(null, $product, ['status' => 'flagged', 'voted_at' => antiCheatNow()->subMinute()]);
    $withdrawn = antiCheatVote(null, $product, ['removed_at' => antiCheatNow(), 'toggle_count' => 2, 'voted_at' => antiCheatNow()->subMinutes(2)]);
    $voided = Vote::factory()->voided()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id, 'voted_at' => antiCheatNow()->subMinutes(3)]);

    // When the list is read
    $response = antiCheatGet("products/{$product->id}/votes")->assertOk();

    // Then newest first, with the pinned item shape
    expect(antiCheatIds($response))->toBe([$counted->id, $flagged->id, $withdrawn->id, $voided->id]);
    $item = $response->json('data.0');
    expect(array_keys($item))->toBe(['id', 'status', 'voted_at', 'removed', 'toggle_count', 'voter', 'device_fp', 'ip_fp']);
    expect($item['voter'])->toBe(['id' => $voter->id, 'handle' => 'voter-one', 'risk_score' => 55]);
    expect($item['device_fp'])->toBe(substr(antiCheatHash('dev'), 0, 8))->and($item['ip_fp'])->toBe(substr(antiCheatHash('ip'), 0, 8));
    expect($response->json('data.1.status'))->toBe('flagged')->and($response->json('data.2.removed'))->toBeTrue()->and($response->json('data.2.toggle_count'))->toBe(2)->and($response->json('data.3.status'))->toBe('voided');
    expect($response->getContent())->not->toMatch('/[0-9a-f]{64}/');
});

it('shows null fingerprints for purged hashes', function () {
    // Given a vote whose hashes were purged
    $product = antiCheatLiveProduct();
    antiCheatVote(null, $product, ['device_hash' => null, 'ip_hash' => null]);

    // When read
    // Then null
    expect(antiCheatGet("products/{$product->id}/votes")->assertOk()->json('data.0'))->toMatchArray(['device_fp' => null, 'ip_fp' => null]);
});

it('filters by status and voter, and walks equal timestamps by id', function () {
    // Given six votes at the same second, two flagged
    $product = antiCheatLiveProduct();
    $votes = array_map(fn (int $i) => antiCheatVote(null, $product, ['status' => $i < 2 ? 'flagged' : 'counted']), range(0, 5));

    // When walked with two per page, filtered by status, and by voter
    $walk = antiCheatWalk("products/{$product->id}/votes", []);
    $flagged = antiCheatWalk("products/{$product->id}/votes", ['status' => 'flagged']);
    $one = antiCheatIds(antiCheatGet("products/{$product->id}/votes", ['user' => $votes[3]->user_id]));

    // Then every vote once, ids descending on ties; the filters narrow
    $expected = collect($votes)->pluck('id')->sort()->reverse()->values()->all();
    expect($walk)->toBe($expected)->and($flagged)->toBe(collect([$votes[0]->id, $votes[1]->id])->sort()->reverse()->values()->all())->and($one)->toBe([$votes[3]->id]);
});

it('answers 422 for every malformed parameter and tampered cursor', function (string $query) {
    // Given a product
    $product = antiCheatLiveProduct();
    antiCheatVote(null, $product);

    // When read with the bad parameter
    // Then 422
    antiCheatGet("products/{$product->id}/votes", $query)->assertStatus(422);
})->with([
    'status unknown' => 'status=pending',
    'status array' => 'status[]=counted',
    'status object' => 'status[a]=counted',
    'status null' => 'status=',
    'user not a ulid' => 'user=abc',
    'user array' => 'user[]=x',
    'user null' => 'user=',
    'per_page zero' => 'per_page=0',
    'per_page over' => 'per_page=101',
    'per_page array' => 'per_page[]=2',
    'cursor garbage' => 'cursor=!!!',
    'cursor json null' => 'cursor=bnVsbA',
    'cursor array' => 'cursor[a]=b',
]);

it('rejects cursors of the wrong shape and serves an empty cursor as page one', function () {
    // Given a product with a vote
    $product = antiCheatLiveProduct();
    antiCheatVote(null, $product);
    $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When wrong-shaped cursors are sent
    // Then 422
    foreach ([
        ['voted_at' => '2026-10-21T12:00:00.000000Z', 'id' => $ulid],
        ['voted_at' => '2026-02-31T12:00:00.000000Z', 'id' => $ulid, '_pointsToNextItems' => true],
        ['voted_at' => 5, 'id' => $ulid, '_pointsToNextItems' => true],
        ['voted_at' => '2026-10-21T12:00:00.000000Z', 'id' => 'x', '_pointsToNextItems' => true],
        ['weight' => 1, 'id' => $ulid, '_pointsToNextItems' => true],
    ] as $payload) {
        antiCheatGet("products/{$product->id}/votes", ['cursor' => antiCheatCursor($payload)])->assertStatus(422);
    }

    // And an empty cursor is page one
    antiCheatGet("products/{$product->id}/votes", 'cursor=')->assertOk();
});

it('carries the filters in the next link', function () {
    // Given three flagged votes
    $product = antiCheatLiveProduct();
    foreach (range(1, 3) as $_) {
        antiCheatVote(null, $product, ['status' => 'flagged']);
    }

    // When the first page is read
    $next = antiCheatGet("products/{$product->id}/votes", ['per_page' => 2, 'status' => 'flagged'])->assertOk()->json('links.next');

    // Then the link keeps both
    expect($next)->toContain('per_page=2')->toContain('status=flagged');
});

it('runs the same number of queries for 5 and for 50 votes', function () {
    // Given five votes, counted, then fifty
    $product = antiCheatLiveProduct();
    foreach (range(1, 5) as $_) {
        antiCheatVote(null, $product);
    }
    $count = function () use ($product): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        antiCheatGet("products/{$product->id}/votes", ['per_page' => 100])->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $five = $count();
    foreach (range(1, 45) as $_) {
        antiCheatVote(null, $product);
    }

    // When read again
    // Then the same number of queries
    expect($count())->toBe($five);
});

it('has no public route that lists voters', function () {
    // Given a product with votes
    $product = antiCheatLiveProduct();
    antiCheatVote(null, $product);

    // When the unprefixed path is probed
    // Then it is not found or not allowed
    expect($this->getJson("/api/v1/products/{$product->id}/votes")->status())->toBeIn([404, 405]);
    expect($this->getJson("/api/v1/products/{$product->slug}/votes")->status())->toBeIn([404, 405]);
});
