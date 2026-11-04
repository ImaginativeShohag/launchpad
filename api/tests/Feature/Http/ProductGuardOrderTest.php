<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('answers a suspended or unverified member the same 403 for an existing and a missing id', function (string $state, string $code) {
    // Given a member who may not use the product routes, and one product that exists
    $member = signedInMember();
    $state === 'suspended' ? $member->forceFill(['suspended_at' => now()])->save() : $member->forceFill(['email_verified_at' => null])->save();
    $product = Product::factory()->complete()->create(['maker_id' => $member->id]);
    $topic = Topic::factory()->create(['slug' => 'real-topic']);
    $other = User::factory()->create(['handle' => 'real-member']);
    $missing = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When the same routes are called with the existing id and with a missing id
    $calls = fn (string $id, string $topicId, string $handle, string $slug) => [
        $this->getJson("/api/v1/me/products/{$id}"),
        $this->patchJson("/api/v1/products/{$id}", ['tagline' => 'Hello there']),
        $this->deleteJson("/api/v1/products/{$id}"),
        $this->postJson("/api/v1/products/{$id}/submit"),
        $this->postJson("/api/v1/products/{$id}/comments", ['body' => 'Hello there']),
        $this->patchJson("/api/v1/comments/{$id}", ['body' => 'Hello there']),
        $this->deleteJson("/api/v1/comments/{$id}"),
        $this->getJson("/api/v1/staff/products/{$id}"),
        $this->patchJson("/api/v1/admin/topics/{$topicId}", ['name' => 'Renamed']),
        $this->putJson("/api/v1/users/{$handle}/follow"),
        $this->deleteJson("/api/v1/users/{$handle}/follow"),
        $this->putJson("/api/v1/topics/{$slug}/follow"),
        $this->deleteJson("/api/v1/topics/{$slug}/follow"),
        $this->postJson("/api/v1/comments/{$id}/report", ['reason' => 'spam']),
        $this->postJson("/api/v1/products/{$id}/report", ['reason' => 'spam']),
        $this->getJson('/api/v1/me/feed'),
        $this->getJson('/api/v1/me/follows/users'),
        $this->getJson('/api/v1/me/follows/topics'),
        $this->getJson("/api/v1/staff/reports/comment/{$id}"),
        $this->getJson("/api/v1/staff/reports/product/{$id}"),
        $this->getJson('/api/v1/staff/reports'),
        $this->getJson('/api/v1/staff/reports/history'),
        $this->postJson("/api/v1/staff/reports/comment/{$id}/resolve", ['action' => 'dismiss']),
        $this->postJson("/api/v1/staff/reports/product/{$id}/resolve", ['action' => 'dismiss']),
        $this->postJson("/api/v1/admin/users/{$handle}/suspend", ['reason' => 'Reason']),
        $this->postJson("/api/v1/admin/users/{$handle}/restore"),
    ];

    // Then every response is the guard's 403, never a 404 that reveals whether the id exists
    foreach ([...$calls($product->id, (string) $topic->id, $other->handle, $topic->slug), ...$calls($missing, '999999', 'no-such-member', 'no-such-topic')] as $response) {
        $response->assertForbidden()->assertJsonPath('code', $code);
    }

    // And the bell and preference routes sit behind `active` only: a suspended member gets the same 403, an unverified one is let through
    $bell = [$this->getJson('/api/v1/me/notifications'), $this->getJson('/api/v1/me/notifications/unread-count'), $this->postJson('/api/v1/me/notifications/read', ['all' => true]), $this->getJson('/api/v1/me/email-preferences'), $this->patchJson('/api/v1/me/email-preferences', ['preferences' => ['daily_top5' => false]])];
    foreach ($bell as $response) {
        $state === 'suspended' ? $response->assertForbidden()->assertJsonPath('code', 'account_unavailable') : $response->assertOk();
    }
})->with([['suspended', 'account_unavailable'], ['unverified', 'email_unverified']]);

it('answers a suspended or unverified member the same 403 on all eight dashboard routes for an existing and a missing id', function (string $state, string $code) {
    // Given a member who may not use the product routes, and one product that exists (a separate test: the api bucket is 60 a minute)
    $member = signedInMember();
    $state === 'suspended' ? $member->forceFill(['suspended_at' => now()])->save() : $member->forceFill(['email_verified_at' => null])->save();
    $product = Product::factory()->live()->create(['maker_id' => $member->id]);

    // When the dashboard routes of both prefixes are called with the existing and a missing id
    foreach (['me', 'staff'] as $prefix) {
        foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
            foreach (['', '/series', '/rank', '/referrers'] as $suffix) {
                // Then every response is the guard's 403
                $this->getJson("/api/v1/{$prefix}/products/{$id}/dashboard{$suffix}")->assertForbidden()->assertJsonPath('code', $code);
            }
        }
    }
})->with([['suspended', 'account_unavailable'], ['unverified', 'email_unverified']]);

/** The six anti-cheat staff reads for an id. */
function antiCheatStaffReads(string $id): array
{
    return ['risk-signals', "risk-signals/{$id}", 'risk/members', "risk/members/{$id}", "products/{$id}/vote-integrity", "products/{$id}/votes"];
}

/** The four anti-cheat staff writes for an id (path => body). */
function antiCheatStaffWrites(string $id): array
{
    return [
        "risk-signals/{$id}/review" => ['outcome' => 'confirmed'], "votes/{$id}/void" => ['reason' => 'Ring vote here'],
        "risk/members/{$id}/void-votes" => ['reason' => 'Ring votes here'], "risk/members/{$id}/suspend" => ['reason' => 'Ring of fakes'],
    ];
}

it('answers a suspended or unverified member the same 403 on the six anti-cheat staff reads for an existing and a missing id', function (string $state, string $code) {
    // Given a suspended or unverified reviewer and one product that exists
    $staff = signedInStaff();
    $state === 'suspended' ? $staff->forceFill(['suspended_at' => now()])->save() : $staff->forceFill(['email_verified_at' => null])->save();
    $product = Product::factory()->live()->create();

    // When the routes are called with the existing and a missing id
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        foreach (antiCheatStaffReads($id) as $path) {
            // Then every response is the guard's 403, never a 404
            $this->getJson("/api/v1/staff/{$path}")->assertForbidden()->assertJsonPath('code', $code);
        }
    }
})->with([['suspended', 'account_unavailable'], ['unverified', 'email_unverified']]);

it('answers a suspended or unverified admin the same 403 on the four anti-cheat staff writes for an existing and a missing id', function (string $state, string $code) {
    // Given a suspended or unverified admin and one product that exists
    $staff = signedInStaff(Role::Admin);
    $state === 'suspended' ? $staff->forceFill(['suspended_at' => now()])->save() : $staff->forceFill(['email_verified_at' => null])->save();
    $product = Product::factory()->live()->create();

    // When the writes are posted with the existing and a missing id
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        foreach (antiCheatStaffWrites($id) as $path => $body) {
            // Then every response is the guard's 403, never a 404 or a 422
            $this->postJson("/api/v1/staff/{$path}", $body)->assertForbidden()->assertJsonPath('code', $code);
        }
    }
})->with([['suspended', 'account_unavailable'], ['unverified', 'email_unverified']]);

it('answers a guest 401 on the four anti-cheat staff writes, and a non-staff member 403 on all four, for an existing and a missing id', function () {
    // Given a product
    $product = Product::factory()->live()->create();

    // When a guest posts, then a member
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        foreach (antiCheatStaffWrites($id) as $path => $body) {
            $this->postJson("/api/v1/staff/{$path}", $body)->assertUnauthorized();
        }
    }
    signedInMember();
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        foreach (antiCheatStaffWrites($id) as $path => $body) {
            $this->postJson("/api/v1/staff/{$path}", $body)->assertForbidden();
        }
    }
});

it('answers a guest 401 on the six anti-cheat staff reads for an existing and a missing id', function () {
    // Given a product and no sign-in
    $product = Product::factory()->live()->create();

    // When a guest calls the routes with the existing and a missing id
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        foreach (antiCheatStaffReads($id) as $path) {
            // Then every response is 401
            $this->getJson("/api/v1/staff/{$path}")->assertUnauthorized();
        }
    }
});

it('answers a suspended or unverified member the same 403 on the export routes for an existing and a missing id', function (string $state, string $code) {
    // Given a member who may not use the product routes, and one product that exists
    $member = signedInMember();
    $state === 'suspended' ? $member->forceFill(['suspended_at' => now()])->save() : $member->forceFill(['email_verified_at' => null])->save();
    $product = Product::factory()->live()->create(['maker_id' => $member->id]);

    // When the export routes are called with existing and missing product and export ids
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        // (the start limiter is a route middleware that runs before the guards and allows 3 an hour, so the POST is sent once per id)
        $this->postJson("/api/v1/me/products/{$id}/exports")->assertForbidden()->assertJsonPath('code', $code);
        foreach (['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid'] as $exportId) {
            // Then every response is the guard's 403
            $this->getJson("/api/v1/me/products/{$id}/exports")->assertForbidden()->assertJsonPath('code', $code);
            $this->getJson("/api/v1/me/products/{$id}/exports/{$exportId}")->assertForbidden()->assertJsonPath('code', $code);
            $this->getJson("/api/v1/me/products/{$id}/exports/{$exportId}/download")->assertForbidden()->assertJsonPath('code', $code);
        }
    }
})->with([['suspended', 'account_unavailable'], ['unverified', 'email_unverified']]);

it('answers a guest 401 for an existing and a missing product id, before any binding', function () {
    // Given a product and no sign-in
    $product = Product::factory()->complete()->create();

    // When a guest calls a founder route with the existing and a missing id
    $existing = $this->patchJson("/api/v1/products/{$product->id}", ['tagline' => 'Hello there']);
    $missing = $this->patchJson('/api/v1/products/01ARZ3NDEKTSV4RRFFQ69G5FAV', ['tagline' => 'Hello there']);

    // Then both are 401
    $existing->assertUnauthorized();
    $missing->assertUnauthorized();

    // And so are the comment routes, for existing and missing ids
    foreach ([$product->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        $this->postJson("/api/v1/products/{$id}/comments", ['body' => 'Hello there'])->assertUnauthorized();
        $this->patchJson("/api/v1/comments/{$id}", ['body' => 'Hello there'])->assertUnauthorized();
        $this->deleteJson("/api/v1/comments/{$id}")->assertUnauthorized();
        $this->postJson("/api/v1/comments/{$id}/report", ['reason' => 'spam'])->assertUnauthorized();
        $this->postJson("/api/v1/products/{$id}/report", ['reason' => 'spam'])->assertUnauthorized();
        $this->putJson("/api/v1/users/{$id}/follow")->assertUnauthorized();
        $this->deleteJson("/api/v1/users/{$id}/follow")->assertUnauthorized();
        $this->putJson("/api/v1/topics/{$id}/follow")->assertUnauthorized();
        $this->deleteJson("/api/v1/topics/{$id}/follow")->assertUnauthorized();
        $this->getJson("/api/v1/staff/reports/comment/{$id}")->assertUnauthorized();
        $this->postJson("/api/v1/staff/reports/product/{$id}/resolve", ['action' => 'dismiss'])->assertUnauthorized();
        $this->postJson("/api/v1/admin/users/{$id}/suspend", ['reason' => 'Reason'])->assertUnauthorized();
        $this->postJson("/api/v1/admin/users/{$id}/restore")->assertUnauthorized();
        foreach (['', '/series', '/rank', '/referrers'] as $suffix) {
            $this->getJson("/api/v1/me/products/{$id}/dashboard{$suffix}")->assertUnauthorized();
            $this->getJson("/api/v1/staff/products/{$id}/dashboard{$suffix}")->assertUnauthorized();
        }
        $this->postJson("/api/v1/me/products/{$id}/exports")->assertUnauthorized();
        $this->getJson("/api/v1/me/products/{$id}/exports")->assertUnauthorized();
        $this->getJson("/api/v1/me/products/{$id}/exports/{$id}")->assertUnauthorized();
        $this->getJson("/api/v1/me/products/{$id}/exports/{$id}/download")->assertUnauthorized();
    }
    foreach (['/api/v1/me/feed', '/api/v1/me/follows/users', '/api/v1/me/follows/topics', '/api/v1/staff/reports', '/api/v1/staff/reports/history', '/api/v1/me/notifications', '/api/v1/me/notifications/unread-count'] as $uri) {
        $this->getJson($uri)->assertUnauthorized();
    }
    $this->postJson('/api/v1/me/notifications/read', ['all' => true])->assertUnauthorized();
    $this->getJson('/api/v1/me/email-preferences')->assertUnauthorized();
    $this->patchJson('/api/v1/me/email-preferences', ['preferences' => ['daily_top5' => false]])->assertUnauthorized();
});

it('answers the view beacon the same for a suspended member, an invalid token and a guest', function () {
    // Given a visible product, and Redis cleared
    viewRedisReset();
    $product = viewVisibleProduct();
    $suspended = User::factory()->suspended()->create();

    // When a suspended member, a junk bearer token and a guest each send the beacon
    Sanctum::actingAs($suspended);
    $asSuspended = viewBeacon($product->id, [], viewDevice('device-a'));
    app('auth')->forgetGuards();
    $junk = test()->withToken('junk-token')->postJson("/api/v1/products/{$product->id}/view", [], viewDevice('device-b'));
    app('auth')->forgetGuards();
    $guest = viewBeacon($product->id, [], viewDevice('device-c'));

    // Then all three are the same empty 204 (the guard middleware never runs on this public route) and all three counted
    foreach ([$asSuspended, $junk, $guest] as $response) {
        $response->assertNoContent();
        expect($response->getContent())->toBe('');
    }
    expect(array_sum(array_map('intval', array_filter(viewPendingHash(), fn ($v, $k) => str_starts_with($k, 'r|'), ARRAY_FILTER_USE_BOTH))))->toBe(3);
});
