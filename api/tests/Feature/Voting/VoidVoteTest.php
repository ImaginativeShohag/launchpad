<?php

use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoidVote;
use App\Actions\Vote\VoteSignals;
use App\Actions\Vote\WithdrawVote;
use App\Enums\VoteStatus;
use App\Events\VoteEvent;
use App\Events\VoteVoided;
use App\Exceptions\ProductRuleViolation;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-19 10:30:00 UTC')));
afterEach(fn () => AuditLog::flushEventListeners());

/** A live product launching today and a member who casts a real vote on it (count, bucket and row all consistent). */
function castOnToday(?Product $product = null): array
{
    $product ??= Product::factory()->live(Carbon::now('UTC')->toDateString())->create();
    $voter = User::factory()->create();
    app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));

    return [$product, $voter, Vote::query()->where('user_id', $voter->id)->where('product_id', $product->id)->sole()];
}

function bucketsOfProduct(Product $product): array
{
    return DB::table('product_hourly_stats')->where('product_id', $product->id)->orderBy('hour_start')->pluck('votes', 'hour_start')->all();
}

it('voids an active vote: status, counters and the ORIGINAL hour bucket, one event, an audit row with ids only', function () {
    // Given a vote cast at 10:30 and a clock that has moved on to 12:00
    Event::fake([VoteVoided::class]);
    [$product, $voter, $vote] = castOnToday();
    $this->travelTo(new DateTimeImmutable('2026-10-19 12:00:00 UTC'));

    // When it is voided by the system
    $result = app(VoidVote::class)->handle($vote->id, 'account_deleted');

    // Then the vote is voided with the reason on the row, the counters went back, and only the 10:00 bucket moved
    expect($result)->toBeTrue();
    $fresh = $vote->fresh();
    expect($fresh->status)->toBe(VoteStatus::Voided)->and($fresh->voided_at->toIso8601String())->toBe('2026-10-19T12:00:00+00:00')->and($fresh->voided_reason)->toBe('account_deleted');
    expect($product->fresh()->votes_count)->toBe(0)->and(bucketsOfProduct($product))->toBe(['2026-10-19 10:00:00+00' => 0]);
    Event::assertDispatchedTimes(VoteVoided::class, 1);
    Event::assertDispatched(VoteVoided::class, fn (VoteVoided $e) => $e->productId === $product->id && $e->userId === $voter->id);
    $log = AuditLog::query()->where('action', 'vote.voided')->sole();
    expect($log->actor_id)->toBeNull()->and($log->subject_id)->toBe($vote->id);
    expect($log->meta)->toEqual(['vote_id' => $vote->id, 'product_id' => $product->id]);
});

it('voids a flagged vote the same way (flagged still counts)', function () {
    // Given a flagged vote
    [$product, , $vote] = castOnToday();
    $vote->forceFill(['status' => VoteStatus::Flagged])->save();

    // When it is voided
    app(VoidVote::class)->handle($vote->id, 'abuse');

    // Then the counter went back
    expect($product->fresh()->votes_count)->toBe(0)->and($vote->fresh()->status)->toBe(VoteStatus::Voided);
});

it('voids a withdrawn vote without touching the counters (they were already decremented)', function () {
    // Given a vote that was withdrawn, and another member's active vote on the same product
    [$product, $voter, $vote] = castOnToday();
    app(WithdrawVote::class)->handle($voter, $product->id);
    app(CastVote::class)->handle(User::factory()->create(), $product->id, new VoteSignals(null, null, null));
    $before = bucketsOfProduct($product);
    expect($product->fresh()->votes_count)->toBe(1);

    // When the withdrawn vote is voided
    $result = app(VoidVote::class)->handle($vote->id, 'abuse');

    // Then it is voided but the count and buckets are exactly as before (no second decrement)
    expect($result)->toBeTrue()->and($vote->fresh()->status)->toBe(VoteStatus::Voided);
    expect($product->fresh()->votes_count)->toBe(1)->and(bucketsOfProduct($product))->toBe($before);
});

it('is idempotent: a second void returns false and changes, audits and fires nothing', function () {
    // Given a product with two votes, one of them voided already
    [$product, , $vote] = castOnToday();
    castOnToday($product);
    app(VoidVote::class)->handle($vote->id, 'abuse');
    $before = $vote->fresh()->only(['status', 'voided_at', 'voided_reason']);
    Event::fake([VoteVoided::class]);

    // When it is voided again, later
    $this->travelTo(new DateTimeImmutable('2026-10-19 11:30:00 UTC'));
    $second = app(VoidVote::class)->handle($vote->id, 'other reason');

    // Then it answers false and nothing moved
    expect($second)->toBeFalse()->and($vote->fresh()->only(['status', 'voided_at', 'voided_reason']))->toEqual($before);
    expect($product->fresh()->votes_count)->toBe(1)->and(bucketsOfProduct($product))->toBe(['2026-10-19 10:00:00+00' => 1]);
    expect(AuditLog::query()->where('action', 'vote.voided')->count())->toBe(1);
    Event::assertNotDispatched(VoteVoided::class);
});

it('refuses with day_frozen once the launch day has passed and changes nothing', function () {
    // Given a vote cast today and a clock past midnight UTC
    Event::fake([VoteVoided::class]);
    [$product, , $vote] = castOnToday();
    $this->travelTo(new DateTimeImmutable('2026-10-20 00:00:00 UTC'));

    // When it is voided
    $thrown = null;
    try {
        app(VoidVote::class)->handle($vote->id, 'abuse');
    } catch (ProductRuleViolation $e) {
        $thrown = $e;
    }

    // Then the refusal is the 409 day_frozen and nothing at all changed
    expect($thrown)->not->toBeNull()->and($thrown->errorCode)->toBe('day_frozen')->and($thrown->status)->toBe(409);
    expect($vote->fresh()->status)->toBe(VoteStatus::Counted)->and($vote->fresh()->voided_at)->toBeNull();
    expect($product->fresh()->votes_count)->toBe(1)->and(bucketsOfProduct($product))->toBe(['2026-10-19 10:00:00+00' => 1]);
    expect(AuditLog::query()->where('action', 'vote.voided')->exists())->toBeFalse();
    Event::assertNotDispatched(VoteVoided::class);
});

it('judges the day after the lock wait: 23:59:59 voids, 00:00:00 refuses', function () {
    // Given two votes on one product cast today
    [$product, , $first] = castOnToday();
    [, , $second] = castOnToday($product);

    // When one is voided at the last second of the day and the other at midnight
    $this->travelTo(new DateTimeImmutable('2026-10-19 23:59:59 UTC'));
    $ok = app(VoidVote::class)->handle($first->id, 'abuse');
    $this->travelTo(new DateTimeImmutable('2026-10-20 00:00:00 UTC'));
    $refused = fn () => app(VoidVote::class)->handle($second->id, 'abuse');

    // Then the first went through and the second is refused
    expect($ok)->toBeTrue()->and($refused)->toThrow(ProductRuleViolation::class);
    expect($product->fresh()->votes_count)->toBe(1);
});

it('keeps the reason on the vote row, cleaned and cut to 255 characters, and out of the audit meta', function () {
    // Given a vote and a long reason with a control character
    [, , $vote] = castOnToday();
    $reason = "  spam\x07 ".str_repeat('x', 400);

    // When it is voided
    app(VoidVote::class)->handle($vote->id, $reason);

    // Then the stored reason is plain text of at most 255 characters and the audit meta has only ids
    $stored = $vote->fresh()->voided_reason;
    expect(mb_strlen($stored))->toBe(255)->and($stored)->toStartWith('spam')->and($stored)->not->toContain("\x07");
    $meta = AuditLog::query()->where('action', 'vote.voided')->sole()->meta;
    expect(array_keys($meta))->toEqualCanonicalizing(['vote_id', 'product_id'])->and(json_encode($meta))->not->toContain('spam');
});

it('records the staff actor when one is given', function () {
    // Given a vote and a staff member
    [, , $vote] = castOnToday();
    $staff = User::factory()->create();

    // When the staff member voids it
    app(VoidVote::class)->handle($vote->id, 'abuse', $staff);

    // Then the audit row carries the actor
    expect(AuditLog::query()->where('action', 'vote.voided')->sole()->actor_id)->toBe($staff->id);
});

it('answers an unknown vote id with a model-not-found error and changes nothing', function (string $id) {
    // Given no such vote
    // When it is voided
    // Then it is refused before any write
    expect(fn () => app(VoidVote::class)->handle($id, 'abuse'))->toThrow(ModelNotFoundException::class);
    expect(AuditLog::query()->where('action', 'vote.voided')->exists())->toBeFalse();
})->with(['not-an-id', '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

it('rolls everything back when the audit row cannot be written, even on a SQL error', function () {
    // Given a vote and an audit insert that fails with a SQL error
    [$product, , $vote] = castOnToday();
    AuditLog::creating(fn () => DB::statement('select 1/0'));

    // When it is voided
    $attempt = fn () => app(VoidVote::class)->handle($vote->id, 'abuse');

    // Then it throws and the vote, the count and the bucket are untouched
    expect($attempt)->toThrow(QueryException::class);
    expect($vote->fresh()->status)->toBe(VoteStatus::Counted)->and($product->fresh()->votes_count)->toBe(1);
    expect(bucketsOfProduct($product))->toBe(['2026-10-19 10:00:00+00' => 1]);
});

it('leaves the member side alone after a void: cast and withdraw answer without changing the totals', function () {
    // Given a voided vote
    [$product, $voter, $vote] = castOnToday();
    app(VoidVote::class)->handle($vote->id, 'abuse');

    // When the member casts and then withdraws
    $cast = app(CastVote::class)->handle($voter, $product->id, new VoteSignals(null, null, null));
    $withdraw = app(WithdrawVote::class)->handle($voter, $product->id);

    // Then both answer "not voted" and nothing moved
    expect($cast)->toBe(['voted' => false, 'votes_count' => 0])->and($withdraw)->toBe(['voted' => false, 'votes_count' => 0]);
    expect($vote->fresh()->status)->toBe(VoteStatus::Voided)->and($product->fresh()->votes_count)->toBe(0);
    expect(array_sum(bucketsOfProduct($product)))->toBe(0);
});

it('locks the product row before the vote row and never locks a user row', function () {
    // Given a vote and a recorder of queries
    [, , $vote] = castOnToday();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    // When it is voided with an actor (the audit FK needs a key share, which is implicit, not a lock query)
    app(VoidVote::class)->handle($vote->id, 'abuse', User::factory()->create());

    // Then the first FOR UPDATE is on products, before the first votes FOR UPDATE, and no users row is locked
    $locks = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'for update') || str_contains($sql, 'for share') || str_contains($sql, 'for key share'));
    expect($locks->first())->toContain('from "products"')->toContain('for update');
    $productAt = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from "products"') && str_contains($sql, 'for update'));
    $voteAt = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from "votes"') && str_contains($sql, 'for update'));
    expect($productAt)->not->toBeFalse()->and($voteAt)->not->toBeFalse()->and($productAt)->toBeLessThan($voteAt);
    expect($locks->filter(fn (string $sql) => str_contains($sql, '"users"')))->toBeEmpty();
});

it('uses relative SQL for the counter so a concurrent writer is not overwritten', function () {
    // Given a recorder of queries
    [, , $vote] = castOnToday();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });

    // When a vote is voided
    app(VoidVote::class)->handle($vote->id, 'abuse');

    // Then the counter is decremented by a relative, floored statement
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'votes_count = greatest(votes_count - 1, 0)')))->toBeTrue();
});

it('never takes the count below zero even when it has drifted', function () {
    // Given an active vote on a product whose counter drifted to 0
    [$product, , $vote] = castOnToday();
    DB::table('products')->where('id', $product->id)->update(['votes_count' => 0]);

    // When it is voided
    app(VoidVote::class)->handle($vote->id, 'abuse');

    // Then the counter stays at 0
    expect($product->fresh()->votes_count)->toBe(0);
});

it('has a vote event that is dispatched after commit and carries ids only', function () {
    // Given the event
    // When its contract is inspected
    // Then it is a vote event, after commit, with the two ids
    expect(new VoteVoided('p', 'u'))->toBeInstanceOf(VoteEvent::class)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect(array_keys(get_object_vars(new VoteVoided('p', 'u'))))->toBe(['productId', 'userId']);
});

it('runs a real listener once per void and not for a refused or repeated one', function () {
    // Given a real listener (no fake) and a vote
    $seen = [];
    Event::listen(VoteVoided::class, function (VoteVoided $e) use (&$seen) {
        $seen[] = [$e->productId, $e->userId];
    });
    [$product, $voter, $vote] = castOnToday();

    // When it is voided, voided again, and another vote is refused on a frozen day
    app(VoidVote::class)->handle($vote->id, 'abuse');
    app(VoidVote::class)->handle($vote->id, 'abuse');
    [, , $late] = castOnToday($product);
    $this->travelTo(new DateTimeImmutable('2026-10-20 00:00:01 UTC'));
    expect(fn () => app(VoidVote::class)->handle($late->id, 'abuse'))->toThrow(ProductRuleViolation::class);

    // Then the listener ran exactly once
    expect($seen)->toBe([[$product->id, $voter->id]]);
});

it('has a day_frozen refusal that renders as 409 JSON with a stable code', function () {
    // Given the refusal
    $violation = ProductRuleViolation::dayFrozen();

    // When it is rendered
    $response = $violation->render();

    // Then it is a 409 with the code
    expect($response->getStatusCode())->toBe(409)->and($response->getData(true)['code'])->toBe('day_frozen');
    expect($violation)->toBeInstanceOf(ShouldntReport::class);
});

it('judges the frozen day on the clock read after the product lock', function () {
    // Given a vote cast today, and a clock that crosses midnight UTC exactly while the product lock is taken
    [$product, , $vote] = castOnToday();
    DB::listen(function ($query) {
        if (str_contains(strtolower($query->sql), 'from "products"') && str_contains(strtolower($query->sql), 'for update')) {
            test()->travelTo(new DateTimeImmutable('2026-10-20 00:00:00 UTC'));
        }
    });

    // When it is voided
    $void = fn () => app(VoidVote::class)->handle($vote->id, 'abuse');

    // Then the late clock wins: the day is frozen and nothing changes
    expect($void)->toThrow(ProductRuleViolation::class);
    expect($vote->fresh()->status)->not->toBe(VoteStatus::Voided)->and($product->fresh()->votes_count)->toBe(1);
});

it('answers an already voided vote with false even after its day froze', function () {
    // Given a voided vote and a clock past its launch day
    [, , $vote] = castOnToday();
    app(VoidVote::class)->handle($vote->id, 'abuse');
    $this->travelTo(new DateTimeImmutable('2026-10-21 10:00:00 UTC'));

    // When it is voided again
    $again = app(VoidVote::class)->handle($vote->id, 'abuse');

    // Then it is the idempotent false, not a day_frozen refusal
    expect($again)->toBeFalse();
});
