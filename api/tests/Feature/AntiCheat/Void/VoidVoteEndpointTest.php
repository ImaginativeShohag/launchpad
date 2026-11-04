<?php

use App\Actions\AntiCheat\VoidVotesByStaff;
use App\Enums\Role;
use App\Enums\VoteStatus;
use App\Events\VoteVoided;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => antiCheatFreeze());

afterEach(fn () => AuditLog::flushEventListeners());

/** A voter, a launching product and the counted vote on it. */
function antiCheatVoidFixture(int $offset = 0, array $voteAttributes = []): array
{
    $voter = User::factory()->create();
    $product = antiCheatLaunching($offset);
    $vote = antiCheatCountedVote($voter, $product, $voteAttributes);

    return [$voter, $product, $vote];
}

it('voids a counted vote: counter and the original hour bucket go down, audited with the actor, VoteVoided fires once after commit', function () {
    // Given an admin, a counted vote cast an hour ago (so its bucket is not the current one), and a real listener
    $admin = signedInStaff(Role::Admin);
    [, $product, $vote] = antiCheatVoidFixture(0, ['voted_at' => antiCheatNow()->subHour()]);
    $fired = [];
    Event::listen(VoteVoided::class, function (VoteVoided $e) use (&$fired) {
        $fired[] = [$e->productId, DB::transactionLevel()];
    });
    $level = DB::transactionLevel();

    // When the admin voids it
    antiCheatPost("votes/{$vote->id}/void", ['reason' => 'Vote manipulation (ring)'])->assertOk()->assertJsonPath('data.voided', true);

    // Then the vote is voided with the reason, the counter and the original bucket are back at zero
    $row = DB::table('votes')->where('id', $vote->id)->first();
    expect($row->status)->toBe('voided')->and($row->voided_reason)->toBe('Vote manipulation (ring)')->and($row->voided_at)->not->toBeNull();
    expect($product->fresh()->votes_count)->toBe(0)->and((int) DB::table('product_hourly_stats')->where('product_id', $product->id)->sum('votes'))->toBe(0);
    expect(DB::table('product_hourly_stats')->where('product_id', $product->id)->where('hour_start', antiCheatNow()->subHour()->startOfHour()->toDateTimeString().'+00')->value('votes'))->toBe(0);

    // And one vote.voided audit row names the actor and carries ids only, never the reason
    $audit = AuditLog::query()->where('action', 'vote.voided')->get();
    expect($audit)->toHaveCount(1)->and($audit[0]->actor_id)->toBe($admin->id)->and($audit[0]->meta)->toEqual(['vote_id' => $vote->id, 'product_id' => $product->id]);
    expect(json_encode($audit[0]->meta))->not->toContain('ring');

    // And the event fired exactly once, outside the transaction
    expect($fired)->toBe([[$product->id, $level]]);
});

it('voids a flagged vote (counter -1) and a withdrawn vote (no counter change)', function () {
    // Given a flagged vote, and a withdrawn vote on another product (both counters set up the way CastVote leaves them)
    signedInStaff(Role::Admin);
    [, $flaggedProduct, $flagged] = antiCheatVoidFixture(0, ['status' => VoteStatus::Flagged]);
    [, $withdrawnProduct, $withdrawn] = antiCheatVoidFixture(0, ['removed_at' => antiCheatNow()]);
    expect($flaggedProduct->fresh()->votes_count)->toBe(1)->and($withdrawnProduct->fresh()->votes_count)->toBe(0);

    // When both are voided
    antiCheatPost("votes/{$flagged->id}/void", ['reason' => 'Flagged ring vote'])->assertOk()->assertJsonPath('data.voided', true);
    antiCheatPost("votes/{$withdrawn->id}/void", ['reason' => 'Withdrawn ring vote'])->assertOk()->assertJsonPath('data.voided', true);

    // Then the first counter went down, the second stayed at zero (never negative)
    expect($flaggedProduct->fresh()->votes_count)->toBe(0)->and($withdrawnProduct->fresh()->votes_count)->toBe(0);
    expect(antiCheatVoteStatus($flagged))->toBe('voided')->and(antiCheatVoteStatus($withdrawn))->toBe('voided');
});

it('answers voided false for a vote that is already voided, and writes no second audit row', function () {
    // Given a vote voided once
    signedInStaff(Role::Admin);
    [, $product, $vote] = antiCheatVoidFixture();
    antiCheatPost("votes/{$vote->id}/void", ['reason' => 'First void'])->assertOk();

    // When it is voided again
    antiCheatPost("votes/{$vote->id}/void", ['reason' => 'Second void'])->assertOk()->assertJsonPath('data.voided', false);

    // Then nothing moved
    expect(AuditLog::query()->where('action', 'vote.voided')->count())->toBe(1)->and($product->fresh()->votes_count)->toBe(0)->and(DB::table('votes')->where('id', $vote->id)->value('voided_reason'))->toBe('First void');
});

it('answers 409 day_frozen for a frozen day and changes nothing', function () {
    // Given a vote on yesterday's launch
    signedInStaff(Role::Admin);
    [, $product, $vote] = antiCheatVoidFixture(-1);

    // When it is voided
    // Then 409 day_frozen, the vote and the counter are untouched
    antiCheatPost("votes/{$vote->id}/void", ['reason' => 'Too late now'])->assertStatus(409)->assertJsonPath('code', 'day_frozen');
    expect(antiCheatVoteStatus($vote))->toBe('counted')->and($product->fresh()->votes_count)->toBe(1)->and(AuditLog::query()->where('action', 'vote.voided')->count())->toBe(0);
});

it('answers an admin 404 for an unknown or malformed vote id', function () {
    // Given an admin
    signedInStaff(Role::Admin);

    // When ids that name no vote are posted
    // Then 404
    foreach (['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid', 'ab%00cd'] as $id) {
        antiCheatPost("votes/{$id}/void", ['reason' => 'Looks wrong'])->assertNotFound();
    }
});

it('refuses a reviewer and a member with the same 403 for any id and any body, and a guest with 401', function (?Role $role) {
    // Given a vote, and a reviewer, a member or a guest
    [, , $vote] = antiCheatVoidFixture();
    if ($role === Role::Member) {
        signedInMember();
    } elseif ($role !== null) {
        signedInStaff($role);
    }

    // When they post to an existing id, an unknown id, a malformed id, with a good and with garbage bodies
    $responses = [];
    foreach ([$vote->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid'] as $id) {
        foreach ([['reason' => 'A fine reason'], ['reason' => ['x']], [], ['reason' => null]] as $body) {
            $responses[] = antiCheatPost("votes/{$id}/void", $body);
        }
    }

    // Then every answer is the same 403 (or 401), before the lookup and the validation, and nothing was voided
    $status = $role === null ? 401 : 403;
    foreach ($responses as $response) {
        $response->assertStatus($status);
    }
    expect(collect($responses)->map->getContent()->unique())->toHaveCount(1)->and(antiCheatVoteStatus($vote))->toBe('counted');
})->with([[null], [Role::Reviewer], [Role::Member]]);

it('reads the role from the database before it takes any product lock, and refuses a demoted actor', function () {
    // Given a vote, an actor model that is an admin in memory, and the database row demoted since
    [, , $vote] = antiCheatVoidFixture();
    $actor = User::factory()->role(Role::Admin)->create();

    // When the action runs with the admin still active, the first statements are the role read and then the product lock
    $trace = antiCheatTrace(fn () => app(VoidVotesByStaff::class)->vote($actor, $vote->id, 'Ring vote here'));
    $find = fn (string $needle) => collect($trace)->search(fn ($t) => str_contains($t['sql'], $needle));
    expect($find('select "role" from "users"'))->not->toBeFalse()->and($find('select "role" from "users"'))->toBeLessThan($find('from "products" where "products"."id" = ?'));
    expect($trace[$find('from "products" where "products"."id" = ?')]['sql'])->toContain('for update');

    // And once the row is demoted the action refuses before any product statement
    [, , $other] = antiCheatVoidFixture();
    DB::table('users')->where('id', $actor->id)->update(['role' => 'reviewer']);
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });
    expect(fn () => app(VoidVotesByStaff::class)->vote($actor, $other->id, 'Ring vote here'))->toThrow(AuthorizationException::class);
    expect(collect($queries)->filter(fn ($q) => str_contains($q, 'products')))->toBeEmpty()->and(antiCheatVoteStatus($other))->toBe('counted');
});

it('rejects a bad reason with 422 and voids nothing', function (mixed $reason) {
    // Given an admin and a counted vote
    signedInStaff(Role::Admin);
    [, , $vote] = antiCheatVoidFixture();

    // When the reason is wrong
    // Then 422 on reason
    antiCheatPost("votes/{$vote->id}/void", ['reason' => $reason])->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect(antiCheatVoteStatus($vote))->toBe('counted');
})->with([
    'missing' => [null], 'empty' => [''], 'spaces only' => ['      '], '4 chars' => ['abcd'], '256 chars' => [str_repeat('a', 256)],
    'array' => [['abcde']], 'object' => [['a' => 'abcde']], 'number' => [12345], 'true' => [true],
]);

it('rejects a JSON body that is not an object, invalid UTF-8 and a missing reason with 422, and cleans control characters', function () {
    // Given an admin and three counted votes
    signedInStaff(Role::Admin);
    [, , $first] = antiCheatVoidFixture();
    [, , $second] = antiCheatVoidFixture();
    [, , $third] = antiCheatVoidFixture();

    // When raw non-object bodies arrive, and a form carries malformed bytes
    foreach (['[]', 'null', '"abcde"', '5'] as $raw) {
        $this->call('POST', "/api/v1/staff/votes/{$first->id}/void", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertUnprocessable();
    }
    $this->post("/api/v1/staff/votes/{$first->id}/void", ['reason' => "bad \xC3\x28 bytes"], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect(antiCheatVoteStatus($first))->toBe('counted');

    // And control characters are stripped before the length rule and before storing; a 255 character reason is the maximum
    antiCheatPost("votes/{$second->id}/void", ['reason' => "  tidy\x00 reason\x07  "])->assertOk();
    antiCheatPost("votes/{$third->id}/void", ['reason' => str_repeat('b', 255)])->assertOk();
    expect(DB::table('votes')->where('id', $second->id)->value('voided_reason'))->toBe('tidy reason')->and(DB::table('votes')->where('id', $third->id)->value('voided_reason'))->toBe(str_repeat('b', 255));
});
