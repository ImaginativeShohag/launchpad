<?php

use App\Actions\AntiCheat\VoidVotesByStaff;
use App\Actions\Vote\VoidVote;
use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Enums\VoteStatus;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => antiCheatFreeze());

afterEach(fn () => AuditLog::flushEventListeners());

/** POST the member void. */
function antiCheatVoidMember(string $memberId, array $body = ['reason' => 'Vote manipulation (ring)']): TestResponse
{
    return antiCheatPost("risk/members/{$memberId}/void-votes", $body);
}

/** A member with a counted vote on each of the given launch offsets (a repeated offset gets the key `offset#n`). */
function antiCheatVoidMember3(array $offsets = [-1, 0, 1]): array
{
    $member = User::factory()->create();
    $votes = [];
    foreach ($offsets as $offset) {
        $product = antiCheatLaunching($offset);
        $votes[isset($votes[$offset]) ? $offset.'#'.count($votes) : $offset] = [$product, antiCheatCountedVote($member, $product)];
    }

    return [$member, $votes];
}

it('voids only the unfrozen days and counts the frozen-day votes as skipped, untouched', function () {
    // Given an admin and a member with votes on yesterday's (frozen), today's and tomorrow's launches
    $admin = signedInStaff(Role::Admin);
    [$member, $votes] = antiCheatVoidMember3();

    // When the admin voids the member's votes
    $response = antiCheatVoidMember($member->id)->assertOk();

    // Then two are voided, the frozen one is skipped and counted, nothing failed, nothing more to do
    expect($response->json('data'))->toBe(['voided' => 2, 'skipped_frozen' => 1, 'failed' => 0, 'has_more' => false]);
    expect(antiCheatVoteStatus($votes[-1][1]))->toBe('counted')->and(antiCheatVoteStatus($votes[0][1]))->toBe('voided')->and(antiCheatVoteStatus($votes[1][1]))->toBe('voided');
    expect($votes[-1][0]->fresh()->votes_count)->toBe(1)->and($votes[0][0]->fresh()->votes_count)->toBe(0)->and($votes[1][0]->fresh()->votes_count)->toBe(0);
    expect(DB::table('votes')->where('id', $votes[0][1]->id)->value('voided_reason'))->toBe('Vote manipulation (ring)');
    expect(AuditLog::query()->where('action', 'vote.voided')->where('actor_id', $admin->id)->count())->toBe(2);
});

it('writes one summary audit row with exactly the listed meta, and none when nothing happened', function () {
    // Given an admin, a member with two voidable votes, and a member with only a frozen-day vote
    $admin = signedInStaff(Role::Admin);
    [$member] = antiCheatVoidMember3([0, 1]);
    [$frozenOnly] = antiCheatVoidMember3([-1]);
    [$nothing] = antiCheatVoidMember3([]);

    // When all three are processed
    antiCheatVoidMember($member->id, ['reason' => 'Ring accounts'])->assertOk();
    antiCheatVoidMember($frozenOnly->id)->assertOk()->assertJsonPath('data.skipped_frozen', 1)->assertJsonPath('data.voided', 0);
    antiCheatVoidMember($nothing->id)->assertOk()->assertJsonPath('data.voided', 0);

    // Then one summary row exists, for the first member only
    $rows = AuditLog::query()->where('action', 'member.votes_voided')->get();
    expect($rows)->toHaveCount(1)->and($rows[0]->actor_id)->toBe($admin->id)->and($rows[0]->subject_id)->toBe($member->id);
    expect($rows[0]->meta)->toEqual(['user_id' => $member->id, 'product_id' => null, 'voided' => 2, 'skipped_frozen' => 0, 'failed' => 0, 'reason' => 'Ring accounts']);
});

it('limits the call to 100 votes and says there is more, a second call finishes', function () {
    // Given a member with 101 counted votes on 101 launching products
    signedInStaff(Role::Admin);
    $member = User::factory()->create();
    $products = collect(range(1, 101))->map(fn () => antiCheatLaunching(0));
    $products->each(fn (Product $p) => antiCheatCountedVote($member, $p));

    // When the admin voids twice
    $first = antiCheatVoidMember($member->id)->assertOk();
    $second = antiCheatVoidMember($member->id)->assertOk();

    // Then exactly 100 went first with has_more, and the last one in the second call
    expect($first->json('data'))->toBe(['voided' => 100, 'skipped_frozen' => 0, 'failed' => 0, 'has_more' => true]);
    expect($second->json('data'))->toBe(['voided' => 1, 'skipped_frozen' => 0, 'failed' => 0, 'has_more' => false]);
    expect(Vote::query()->active()->where('user_id', $member->id)->count())->toBe(0);
    $sorted = $products->pluck('id')->sort()->values();
    expect(antiCheatVoteStatus(Vote::query()->where('product_id', $sorted->last())->firstOrFail()))->toBe('voided');
});

it('voids one product only when product_id is given, and a product with no vote matches nothing', function () {
    // Given a member with votes on two unfrozen products
    signedInStaff(Role::Admin);
    [$member, $votes] = antiCheatVoidMember3([0, 1]);

    // When one product is named, then an unknown one
    antiCheatVoidMember($member->id, ['reason' => 'Only this one', 'product_id' => $votes[1][0]->id])->assertOk()->assertJsonPath('data.voided', 1);
    antiCheatVoidMember($member->id, ['reason' => 'Only this one', 'product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->assertOk()->assertJsonPath('data.voided', 0);

    // Then only that product's vote is voided, and the summary row records the product
    expect(antiCheatVoteStatus($votes[0][1]))->toBe('counted')->and(antiCheatVoteStatus($votes[1][1]))->toBe('voided');
    expect(AuditLog::query()->where('action', 'member.votes_voided')->firstOrFail()->meta['product_id'])->toBe($votes[1][0]->id);
});

it('reports a failing vote and still voids the rest, answering 200', function () {
    // Given three votes and an audit insert that fails (a SQL error) for the middle product's vote
    Exceptions::fake();
    $admin = signedInStaff(Role::Admin);
    [$member, $votes] = antiCheatVoidMember3([0, 0, 0]);
    $ordered = collect($votes)->map(fn ($pair) => $pair[1])->sortBy('product_id')->values();
    $bad = $ordered[1];
    AuditLog::creating(function (AuditLog $log) use ($bad) {
        if ($log->subject_id === $bad->id) {
            DB::statement('select 1/0');
        }
    });

    // When the admin voids the member's votes
    $response = antiCheatVoidMember($member->id)->assertOk();

    // Then the failure is counted and reported, the other two are voided and the bad one is intact
    expect($response->json('data'))->toBe(['voided' => 2, 'skipped_frozen' => 0, 'failed' => 1, 'has_more' => false]);
    expect(antiCheatVoteStatus($ordered[0]))->toBe('voided')->and(antiCheatVoteStatus($ordered[2]))->toBe('voided')->and(antiCheatVoteStatus($bad))->toBe('counted');
    Exceptions::assertReported(QueryException::class);
    expect(AuditLog::query()->where('action', 'member.votes_voided')->firstOrFail()->meta)->toMatchArray(['voided' => 2, 'failed' => 1]);
    unset($admin);
});

it('counts a day that froze while the call was running as skipped', function () {
    // Given two votes on today's launches and a voider that moves the first product to yesterday just before it runs
    signedInStaff(Role::Admin);
    [$member, $votes] = antiCheatVoidMember3([0, 0]);
    $ordered = collect($votes)->sortBy(fn ($pair) => $pair[0]->id)->values();
    $moving = $ordered[0][0]->id;
    app()->bind(VoidVote::class, fn () => new class($moving) extends VoidVote
    {
        public function __construct(private readonly string $moving) {}

        public function handle(string $voteId, string $reason, ?User $actor = null): bool
        {
            DB::table('products')->where('id', $this->moving)->update(['launch_date' => antiCheatNow()->subDay()->toDateString()]);

            return parent::handle($voteId, $reason, $actor);
        }
    });

    // When the admin voids the member's votes
    $response = antiCheatVoidMember($member->id)->assertOk();

    // Then the raced vote is skipped exactly once and the other is voided
    expect($response->json('data'))->toBe(['voided' => 1, 'skipped_frozen' => 1, 'failed' => 0, 'has_more' => false]);
    expect(antiCheatVoteStatus($ordered[0][1]))->toBe('counted')->and(antiCheatVoteStatus($ordered[1][1]))->toBe('voided');
});

it('leaves the member score and signals untouched and keeps the counters equal to the active votes', function () {
    // Given a member with a score and a signal, and a mixed fixture: counted, flagged, withdrawn and other members' votes
    signedInStaff(Role::Admin);
    $member = antiCheatSetScore(User::factory()->create(), 55);
    $signal = antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 1);
    $shared = antiCheatLaunching(0);
    $other = User::factory()->create();
    antiCheatCountedVote($member, $shared);
    antiCheatCountedVote($other, $shared);
    antiCheatCountedVote($member, antiCheatLaunching(0), ['status' => VoteStatus::Flagged]);
    antiCheatCountedVote($member, antiCheatLaunching(1), ['removed_at' => antiCheatNow()]);
    antiCheatCountedVote($member, antiCheatLaunching(-1));

    // When the member's votes are voided
    antiCheatVoidMember($member->id)->assertOk();

    // Then score and signal are as they were
    expect(antiCheatScoreOf($member))->toBe(55)->and(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull();

    // And every product's counter equals its active votes and its buckets add up to it
    foreach (Product::query()->get() as $product) {
        $active = Vote::query()->active()->where('product_id', $product->id)->count();
        expect($product->votes_count)->toBe($active)->and((int) DB::table('product_hourly_stats')->where('product_id', $product->id)->sum('votes'))->toBe($active);
    }
});

it('lets an admin void the votes of a deleted member and answers 404 for an unknown or malformed member', function () {
    // Given an admin and a deleted member with a vote
    signedInStaff(Role::Admin);
    [$member] = antiCheatVoidMember3([0]);
    DB::table('users')->where('id', $member->id)->update(['deleted_at' => now()]);

    // When the votes are voided, and ids that name no member are posted
    antiCheatVoidMember($member->id)->assertOk()->assertJsonPath('data.voided', 1);
    foreach (['01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid', 'ab%00cd'] as $id) {
        // Then 404
        antiCheatVoidMember($id)->assertNotFound();
    }
});

it('refuses a reviewer and a member with the same 403 for any member id and any body, and a guest with 401', function (?Role $role) {
    // Given a member with a vote, and a reviewer, a member or a guest
    [$member, $votes] = antiCheatVoidMember3([0]);
    if ($role === Role::Member) {
        signedInMember();
    } elseif ($role !== null) {
        signedInStaff($role);
    }

    // When they post to an existing id, an unknown id and a malformed id, with a good and with garbage bodies
    $responses = [];
    foreach ([$member->id, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'not-a-ulid'] as $id) {
        foreach ([['reason' => 'A fine reason'], ['reason' => ['x'], 'product_id' => ['y']], [], ['reason' => 'A fine reason', 'product_id' => 5]] as $body) {
            $responses[] = antiCheatVoidMember($id, $body);
        }
    }

    // Then every answer is the same 403 (401 for a guest) and nothing was voided
    foreach ($responses as $response) {
        $response->assertStatus($role === null ? 401 : 403);
    }
    expect(collect($responses)->map->getContent()->unique())->toHaveCount(1)->and(antiCheatVoteStatus($votes[0][1]))->toBe('counted');
})->with([[null], [Role::Reviewer], [Role::Member]]);

it('refuses a stale actor before looking anything up', function () {
    // Given an actor model that is an admin in memory and was demoted in the database
    [$member] = antiCheatVoidMember3([0]);
    $actor = User::factory()->role(Role::Admin)->create();
    DB::table('users')->where('id', $actor->id)->update(['role' => 'member']);
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });

    // When the action runs for an unknown member
    // Then the authorisation error wins over the missing member, and no vote or product was read
    expect(fn () => app(VoidVotesByStaff::class)->forMember($actor, '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'Ring votes'))->toThrow(AuthorizationException::class);
    expect(collect($queries)->filter(fn ($q) => str_contains($q, 'votes') || str_contains($q, 'products')))->toBeEmpty();
});

it('rejects a bad body with 422 and voids nothing', function (array $body) {
    // Given an admin and a member with a vote
    signedInStaff(Role::Admin);
    [$member, $votes] = antiCheatVoidMember3([0]);

    // When the body is wrong
    // Then 422
    antiCheatVoidMember($member->id, $body)->assertUnprocessable();
    expect(antiCheatVoteStatus($votes[0][1]))->toBe('counted');
})->with([
    'no reason' => [[]], 'empty reason' => [['reason' => '']], 'short reason' => [['reason' => 'abcd']], 'long reason' => [['reason' => str_repeat('a', 256)]],
    'reason array' => [['reason' => ['abcde']]], 'reason object' => [['reason' => ['a' => 'abcde']]], 'reason null' => [['reason' => null]], 'reason number' => [['reason' => 12345]], 'reason bool' => [['reason' => true]],
    'product array' => [['reason' => 'Ring votes', 'product_id' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']]],
    'product object' => [['reason' => 'Ring votes', 'product_id' => ['a' => 1]]],
    'product null' => [['reason' => 'Ring votes', 'product_id' => null]],
    'product empty' => [['reason' => 'Ring votes', 'product_id' => '']],
    'product number' => [['reason' => 'Ring votes', 'product_id' => 5]],
    'product not a ulid' => [['reason' => 'Ring votes', 'product_id' => 'my-product']],
]);

it('rejects a JSON body that is not an object with 422', function (string $raw) {
    // Given an admin and a member
    signedInStaff(Role::Admin);
    [$member] = antiCheatVoidMember3([0]);

    // When a non-object JSON body is posted
    // Then 422, never 500
    $this->call('POST', "/api/v1/staff/risk/members/{$member->id}/void-votes", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertUnprocessable();
})->with(['[]', '[1,2]', 'null', '"abcde"', '5', 'true']);

it('takes the role read, then each vote in product id order (product, then vote, then audit), as its own transaction and never a user lock', function () {
    // Given three members' worth of votes: one member, three products in a known id order
    $actor = User::factory()->role(Role::Admin)->create();
    [$member, $votes] = antiCheatVoidMember3([0, 1, 0]);
    $productIds = collect($votes)->map(fn ($pair) => $pair[0]->id)->sort()->values()->all();
    $base = DB::transactionLevel();

    // When the member's votes are voided
    $trace = antiCheatTrace(fn () => app(VoidVotesByStaff::class)->forMember($actor, $member->id, 'Ring votes'));

    // Then the product rows are locked in ascending id order, one vote at a time
    $locks = collect($trace)->filter(fn ($t) => str_contains($t['sql'], 'from "products" where "products"."id" = ?') && str_contains($t['sql'], 'for update'));
    expect($locks->map(fn ($t) => $t['bindings'][0])->values()->all())->toBe($productIds);

    // And per vote: role read, then product lock, then vote lock, then audit insert
    $roles = collect($trace)->keys()->filter(fn ($i) => str_contains($trace[$i]['sql'], 'select "role" from "users"'))->values();
    $productLocks = collect($trace)->keys()->filter(fn ($i) => str_contains($trace[$i]['sql'], 'from "products" where "products"."id" = ?'))->values();
    $voteLocks = collect($trace)->keys()->filter(fn ($i) => str_contains($trace[$i]['sql'], 'from "votes" where "votes"."id" = ?') && str_contains($trace[$i]['sql'], 'for update'))->values();
    // (the first role read is the up-front check of the whole call; each vote then reads the role twice: before its transaction and inside it)
    expect($roles)->toHaveCount(7)->and($productLocks)->toHaveCount(3)->and($voteLocks)->toHaveCount(3);
    foreach (range(0, 2) as $n) {
        expect($roles[2 * $n + 2] < $productLocks[$n] && $productLocks[$n] < $voteLocks[$n])->toBeTrue();
    }

    // And no user row is locked, and no vote runs inside an outer transaction (each starts at the caller's depth + 1, plus VoidVote's savepoint)
    expect(collect($trace)->filter(fn ($t) => str_contains($t['sql'], 'from "users"') && (str_contains($t['sql'], 'for update') || str_contains($t['sql'], 'for share'))))->toBeEmpty();
    expect(collect([2, 4, 6])->map(fn ($n) => $trace[$roles[$n]]['level'])->unique()->values()->all())->toBe([$base + 1])->and($trace[$roles[0]]['level'])->toBe($base);
});

it('still writes the summary row when the actor loses the role mid-run, and keeps the votes already voided', function () {
    // Given an admin actor, three votes, and a hook that demotes the actor while the first vote is being audited
    $actor = User::factory()->role(Role::Admin)->create();
    [$member] = antiCheatVoidMember3([0, 0, 0]);
    AuditLog::creating(function (AuditLog $log) use ($actor) {
        if ($log->action === 'vote.voided') {
            DB::table('users')->where('id', $actor->id)->update(['role' => 'member']);
        }
    });

    // When the member void runs
    // Then it stops with an authorisation error, the first vote stays voided and the summary row records it
    expect(fn () => app(VoidVotesByStaff::class)->forMember($actor, $member->id, 'Ring votes'))->toThrow(AuthorizationException::class);
    expect(Vote::query()->where('user_id', $member->id)->where('status', 'voided')->count())->toBe(1);
    $summary = AuditLog::query()->where('action', 'member.votes_voided')->firstOrFail();
    expect($summary->meta)->toMatchArray(['voided' => 1, 'failed' => 0]);
});

it('answers 200 when only the summary audit row fails, and reports it', function () {
    // Given an admin, a vote, and a summary audit insert that fails
    Exceptions::fake();
    signedInStaff(Role::Admin);
    [$member, $votes] = antiCheatVoidMember3([0]);
    AuditLog::creating(function (AuditLog $log) {
        if ($log->action === 'member.votes_voided') {
            DB::statement('select 1/0');
        }
    });

    // When the member void runs
    // Then the work is reported as done, never a 500
    antiCheatVoidMember($member->id)->assertOk()->assertJsonPath('data.voided', 1);
    expect(antiCheatVoteStatus($votes[0][1]))->toBe('voided');
    Exceptions::assertReported(QueryException::class);
});

it('checks the role before it looks at the vote id', function () {
    // Given a non-admin actor model
    $actor = User::factory()->create();

    // When the single void gets a malformed id
    // Then the authorisation error wins over the 404
    expect(fn () => app(VoidVotesByStaff::class)->vote($actor, 'not-a-ulid', 'Ring vote here'))->toThrow(AuthorizationException::class);
});
