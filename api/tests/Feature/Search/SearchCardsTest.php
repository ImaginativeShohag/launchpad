<?php

use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('returns the public card keys with votes and no rank or score, for a guest', function () {
    // Given a product by a maker with a private email, in a topic
    searchTopicWithProducts('tools', 1, ['maker_id' => User::factory()->create(['email' => 'secret@example.com'])->id]);

    // When a guest searches
    $response = searchApi('q=quokka')->assertOk();

    // Then the card has exactly the public keys, with maker (with links) and topics
    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'slug', 'name', 'tagline', 'description', 'website_url', 'logo_url', 'launch_date', 'launched_at',
        'votes_count', 'comments_count', 'maker', 'topics',
    ]);
    expect($response->json('data.0.maker'))->toHaveKey('links')->and($response->json('data.0.topics'))->not->toBeEmpty();
});

it('leaks nothing private anywhere in the JSON', function () {
    // Given a taken-down product with a reason, a live one by a maker with a private email, and a topic
    $product = searchTopicWithProducts('tools', 1, ['maker_id' => User::factory()->create(['email' => 'secret@example.com'])->id])[0];
    DB::table('products')->where('id', '!=', $product->id)->delete();
    searchLive('Quokka Down')->forceFill(['status' => 'taken_down', 'taken_down_at' => now(), 'takedown_reason' => 'private-reason'])->save();

    // When searched
    $body = searchApi('q=quokka')->assertOk()->getContent();

    // Then no private or internal name or value appears (not even an @)
    foreach (['secret@example.com', 'private-reason', 'takedown', 'taken_down', 'search_vector', 'name_search', 'hot_score', 'score', 'status', 'review', 'email', 'deleted_at', 'submitted_at', '@'] as $needle) {
        expect(str_contains((string) $body, $needle))->toBeFalse($needle);
    }
});

it('adds has_voted only for a known viewer, and it is correct for a mix', function () {
    // Given a member with an active vote, a withdrawn vote and no vote
    $member = User::factory()->create();
    $voted = searchLive('Quokka One', null, null, ['votes_count' => 5]);
    $withdrawn = searchLive('Quokka Two', null, null, ['votes_count' => 4]);
    searchLive('Quokka Three', null, null, ['votes_count' => 3]);
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $voted->id]);
    Vote::factory()->removed()->create(['user_id' => $member->id, 'product_id' => $withdrawn->id]);

    // When a guest and then the member search
    $guest = searchApi('q=quokka&sort=votes');
    Sanctum::actingAs($member);
    $known = searchApi('q=quokka&sort=votes');

    // Then guests get no key; the member gets true only for the active vote
    foreach ($guest->json('data') as $card) {
        expect($card)->not->toHaveKey('has_voted');
    }
    expect($known->json('data.*.has_voted'))->toBe([true, false, false]);
});

it('treats a suspended or deleted caller as a guest', function (string $state) {
    // Given a member who voted, then suspended or deleted
    $member = User::factory()->create();
    $product = searchLive('Quokka One');
    Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
    DB::table('users')->where('id', $member->id)->update($state === 'suspended' ? ['suspended_at' => now()] : ['deleted_at' => now()]);
    Sanctum::actingAs($member->fresh() ?? User::withTrashed()->findOrFail($member->id));

    // When they search
    $response = searchApi('q=quokka');

    // Then there is no has_voted key and the answer is not private-to-them
    $response->assertOk();
    expect($response->json('data.0'))->not->toHaveKey('has_voted');
    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
})->with(['suspended', 'deleted']);

it('keeps the query count the same for 1, 10 and 50 results, for a guest and for a member', function () {
    // Given a member and a way to count the queries of one search
    $member = User::factory()->create();
    $count = function (bool $signedIn) use ($member): int {
        $signedIn ? Sanctum::actingAs($member) : app('auth')->forgetGuards();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        searchApi('q=quokka&per_page=50')->assertOk();

        return $queries;
    };
    $make = function (int $n) use ($member): void {
        foreach (range(1, $n) as $_) {
            $product = searchLive('Quokka Tool');
            Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id]);
        }
    };

    // When one, ten and fifty products match
    $make(1);
    $one = [$count(false), $count(true)];
    $make(9);
    $ten = [$count(false), $count(true)];
    $make(40);
    $fifty = [$count(false), $count(true)];

    // Then nothing grows, and a signed-in caller adds exactly the one has_voted lookup
    expect($ten)->toBe($one)->and($fifty)->toBe($one);
    expect($one[1])->toBe($one[0] + 1);
});
