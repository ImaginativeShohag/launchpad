<?php

use App\Enums\Role;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;

/** The six reads for a set of existing ids. */
function antiCheatAccessPaths(RiskSignal $signal, User $member, Product $product): array
{
    return ['risk-signals', "risk-signals/{$signal->id}", 'risk/members', "risk/members/{$member->id}", "products/{$product->id}/vote-integrity", "products/{$product->id}/votes"];
}

beforeEach(function () {
    antiCheatFreeze();
    $this->product = antiCheatLiveProduct();
    $this->target = antiCheatSetScore(User::factory()->create(), 80);
    antiCheatVote($this->target, $this->product);
    $this->signal = RiskSignal::factory()->create(['user_id' => $this->target->id, 'product_id' => $this->product->id]);
});

it('serves reviewers and admins on all six reads', function (Role $role) {
    // Given a staff member
    signedInStaff($role);

    // When each read is requested
    // Then 200
    foreach (antiCheatAccessPaths($this->signal, $this->target, $this->product) as $path) {
        antiCheatGet($path)->assertOk();
    }
})->with([[Role::Reviewer], [Role::Admin]]);

it('answers a member the same 403 for an existing id, an unknown id, a non-ULID and an invalid query, before any validation', function () {
    // Given a signed-in member
    signedInMember();
    $missing = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When the reads are requested with existing and unknown ids, and with a garbage query
    $bodies = [];
    foreach ([antiCheatAccessPaths($this->signal, $this->target, $this->product), ['risk-signals', "risk-signals/{$missing}", 'risk/members', "risk/members/{$missing}", "products/{$missing}/vote-integrity", "products/{$missing}/votes"], ['risk-signals', 'risk-signals/nope', 'risk/members', 'risk/members/nope', 'products/nope/vote-integrity', 'products/nope/votes']] as $set) {
        foreach ($set as $i => $path) {
            $response = antiCheatGet($path, 'cursor=!!!&per_page[]=1&status[]=x&min_score=abc')->assertForbidden();
            $bodies[$i][] = $response->getContent();
        }
    }

    // Then every body for the same route is identical whatever the id
    foreach ($bodies as $same) {
        expect(array_unique($same))->toHaveCount(1);
    }
});

it('answers staff 404 for an unknown or malformed id, ahead of validation', function () {
    // Given a reviewer
    signedInStaff();

    // When ids that do not exist are requested (with a query that would fail validation on the votes list)
    // Then 404, never 422 or 500
    foreach (['risk-signals/01ARZ3NDEKTSV4RRFFQ69G5FAV', 'risk-signals/nope', 'risk/members/01ARZ3NDEKTSV4RRFFQ69G5FAV', 'risk/members/nope', 'products/01ARZ3NDEKTSV4RRFFQ69G5FAV/vote-integrity', 'products/nope/vote-integrity', 'products/01ARZ3NDEKTSV4RRFFQ69G5FAV/votes', 'products/nope/votes', 'risk-signals/ab%00cd', 'risk/members/ab%00cd', 'products/ab%00cd/vote-integrity'] as $path) {
        antiCheatGet($path, str_ends_with($path, '/votes') ? 'cursor=!!!' : '')->assertNotFound();
    }
});

it('answers a draft, a soft-deleted draft and an unknown product with the very same 404 as the staff product detail', function () {
    // Given a reviewer, a draft, a soft-deleted draft
    signedInStaff();
    $draft = Product::factory()->create();
    $trashed = Product::factory()->create();
    $trashed->delete();
    $reference = $this->getJson('/api/v1/staff/products/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound()->getContent();

    // When the product anti-cheat reads are requested for them
    foreach ([$draft->id, $trashed->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV'] as $id) {
        foreach (['vote-integrity', 'votes'] as $suffix) {
            // Then the 404 body is the same as the staff product detail's
            expect(antiCheatGet("products/{$id}/{$suffix}")->assertNotFound()->getContent())->toBe($reference);
        }
    }
    expect($this->getJson("/api/v1/staff/products/{$draft->id}")->assertNotFound()->getContent())->toBe($reference);
});

it('validates only after the product was found: a real product with a bad cursor is 422', function () {
    // Given a reviewer
    signedInStaff();

    // When the votes of a real product are requested with a bad cursor
    // Then 422
    antiCheatGet("products/{$this->product->id}/votes", 'cursor=!!!')->assertStatus(422);
});

it('ignores a JSON body and any query on the parameterless reads', function () {
    // Given a reviewer
    signedInStaff();

    // When the detail reads carry junk in the query and a body
    // Then they still answer 200
    $this->getJson("/api/v1/staff/risk-signals/{$this->signal->id}?x[]=1", ['CONTENT_TYPE' => 'application/json'])->assertOk();
    $this->call('GET', "/api/v1/staff/risk/members/{$this->target->id}", ['junk' => ['a' => 1]], [], [], ['HTTP_ACCEPT' => 'application/json'], '{"a":[1]}')->assertOk();
    antiCheatGet("products/{$this->product->id}/vote-integrity", 'per_page[]=1&cursor=!!!')->assertOk();
});
