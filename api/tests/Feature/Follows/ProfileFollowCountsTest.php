<?php

use App\Actions\Auth\DeleteAccount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/FollowFixtures.php';

it('shows follower and following counts on the public profile, with no is_following for a guest', function () {
    // Given a member followed by two and following one
    $member = User::factory()->create(['handle' => 'amina_r']);
    followRow(User::factory()->create(), $member);
    followRow(User::factory()->create(), $member);
    followRow($member, User::factory()->create());

    // When a guest reads the profile
    $response = $this->getJson('/api/v1/users/amina_r');

    // Then the counts are right and the viewer key is absent
    $response->assertOk()->assertJsonPath('data.followers_count', 2)->assertJsonPath('data.following_count', 1)->assertJsonMissingPath('data.is_following');
});

it('moves with follows and unfollows by several members', function () {
    // Given a profile and three members
    $target = User::factory()->create();
    $members = User::factory()->count(3)->create();

    // When they follow and one unfollows
    foreach ($members as $m) {
        Sanctum::actingAs($m);
        $this->putJson("/api/v1/users/{$target->handle}/follow")->assertOk();
    }
    Sanctum::actingAs($members[0]);
    $this->deleteJson("/api/v1/users/{$target->handle}/follow")->assertOk();
    $this->app['auth']->forgetGuards();

    // Then the profile counts two followers
    $this->getJson("/api/v1/users/{$target->handle}")->assertJsonPath('data.followers_count', 2);
});

it('shows is_following true or false to a known viewer, false for the owner', function () {
    // Given a viewer who follows one member and not another
    $viewer = signedInMember();
    $followed = User::factory()->create();
    $stranger = User::factory()->create();
    followRow($viewer, $followed);

    // When they read both profiles and their own
    // Then the flag reflects the follow, and is false (present) on their own profile
    $this->getJson("/api/v1/users/{$followed->handle}")->assertJsonPath('data.is_following', true);
    $this->getJson("/api/v1/users/{$stranger->handle}")->assertJsonPath('data.is_following', false);
    $this->getJson("/api/v1/users/{$viewer->handle}")->assertJsonPath('data.is_following', false);
});

it('treats a suspended viewer as a guest: no is_following', function () {
    // Given a suspended member who follows somebody
    $viewer = User::factory()->suspended()->create();
    $target = User::factory()->create();
    followRow($viewer, $target);
    Sanctum::actingAs($viewer);

    // When they read the profile
    // Then there is no viewer key and no no-store header (they are a guest)
    $response = $this->getJson("/api/v1/users/{$target->handle}");
    $response->assertOk()->assertJsonMissingPath('data.is_following');
    expect($response->headers->get('Cache-Control'))->not->toContain('no-store');
});

it('keeps the counts on a suspended profile', function () {
    // Given a suspended member with a follower
    $member = User::factory()->suspended()->create();
    followRow(User::factory()->create(), $member);

    // When the profile is read
    // Then it is reduced but still carries the counts
    $this->getJson("/api/v1/users/{$member->handle}")->assertOk()->assertJsonPath('data.is_suspended', true)
        ->assertJsonPath('data.followers_count', 1)->assertJsonPath('data.following_count', 0);
});

it('drops a deleted member from the counts of the members they followed and who followed them', function () {
    // Given a member followed by one and following one
    $deleted = User::factory()->create();
    $followed = User::factory()->create();
    $follower = User::factory()->create();
    followRow($deleted, $followed);
    followRow($follower, $deleted);

    // When the member deletes their account
    app(DeleteAccount::class)->handle($deleted);

    // Then the counts of the others no longer include them
    $this->getJson("/api/v1/users/{$followed->handle}")->assertJsonPath('data.followers_count', 0);
    $this->getJson("/api/v1/users/{$follower->handle}")->assertJsonPath('data.following_count', 0);
});

it('uses the viewer cache policy: Vary always, no-store only for a known viewer', function () {
    // Given a profile
    $target = User::factory()->create();

    // When a guest and a member read it
    $guest = $this->getJson("/api/v1/users/{$target->handle}");
    signedInMember();
    $member = $this->getJson("/api/v1/users/{$target->handle}");

    // Then both vary on credentials, only the member's is no-store
    expect($guest->headers->get('Vary'))->toContain('Authorization')->toContain('Cookie');
    expect($guest->headers->get('Cache-Control'))->not->toContain('no-store');
    expect($member->headers->get('Vary'))->toContain('Authorization')->toContain('Cookie');
    expect($member->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('adds only followers_count (and is_following for a known viewer) to the maker card on a product read', function () {
    // Given a live product with a maker who has followers
    $product = Product::factory()->live()->create();
    followRow(User::factory()->create(), $product->maker);

    // When the product is read
    $maker = $this->getJson("/api/v1/products/{$product->slug}")->assertOk()->json('data.maker');

    // Then the card has the follower count, and no following count or flag for a guest
    expect($maker)->toHaveKey('followers_count')->not->toHaveKeys(['following_count', 'is_following', 'products_count']);
});

it('runs a constant number of queries for the profile', function () {
    // Given a profile with many followers
    $target = User::factory()->create();
    foreach (User::factory()->count(5)->create() as $f) {
        followRow($f, $target);
    }
    $count = function () use ($target): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        $this->getJson("/api/v1/users/{$target->handle}")->assertOk();

        return $n;
    };
    $small = $count();

    // When more followers exist
    foreach (User::factory()->count(20)->create() as $f) {
        followRow($f, $target);
    }

    // Then the query count does not grow
    expect($count() - $small)->toBeLessThanOrEqual(0)->and($small)->toBeLessThanOrEqual(8);
});
