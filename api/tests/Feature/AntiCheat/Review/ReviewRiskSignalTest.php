<?php

use App\Actions\AntiCheat\ReviewRiskSignal;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    antiCheatFreeze();
});

afterEach(fn () => AuditLog::flushEventListeners());

/** POST the review of a signal. */
function antiCheatReview(string $id, mixed $body): TestResponse
{
    return test()->postJson("/api/v1/staff/risk-signals/{$id}/review", $body);
}

/** A member with one open weight-40 signal detected a week ago (half decayed: 20 open, 40 confirmed, 0 dismissed) and the matching stored score. */
function antiCheatReviewSubject(): array
{
    $member = User::factory()->create();
    $signal = antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 7);
    antiCheatSetScore($member, 20);

    return [$member, $signal];
}

it('confirms an open signal: review columns, full weight in the score at once, one audit row with ids only', function () {
    // Given a reviewer and a member whose half-decayed open signal scores 20
    $reviewer = signedInStaff();
    [$member, $signal] = antiCheatReviewSubject();

    // When the reviewer confirms it with a note
    $response = antiCheatReview($signal->id, ['outcome' => 'confirmed', 'note' => 'Same laptop, five accounts'])->assertOk();

    // Then the columns, the response and the score reflect it immediately
    $row = DB::table('risk_signals')->where('id', $signal->id)->first();
    expect($row->outcome)->toBe('confirmed')->and($row->reviewed_by)->toBe($reviewer->id)->and($row->review_note)->toBe('Same laptop, five accounts')
        ->and($row->reviewed_at)->toStartWith('2026-10-21 12:00:00');
    expect($response->json('data.status'))->toBe('confirmed')->and($response->json('data.outcome'))->toBe('confirmed')->and($response->json('meta.changed'))->toBeTrue()
        ->and($response->json('data.member.risk_score'))->toBe(40)->and($response->json('data.reviewer.id'))->toBe($reviewer->id);
    expect(antiCheatScoreOf($member))->toBe(40);

    // And exactly one audit row names the actor and carries the listed meta, without the note text
    $audits = AuditLog::query()->where('action', 'risk_signal.reviewed')->get();
    expect($audits)->toHaveCount(1)->and($audits[0]->actor_id)->toBe($reviewer->id)->and($audits[0]->subject_id)->toBe($signal->id);
    expect($audits[0]->meta)->toEqual(['signal_id' => $signal->id, 'kind' => 'shared_device', 'user_id' => $member->id, 'product_id' => null, 'before' => null, 'after' => 'confirmed']);
    expect(json_encode($audits[0]->meta))->not->toContain('laptop');
});

it('dismisses an open signal and drops its contribution from the score at once', function () {
    // Given an admin and the same half-decayed signal
    signedInStaff(Role::Admin);
    [$member, $signal] = antiCheatReviewSubject();

    // When it is dismissed
    antiCheatReview($signal->id, ['outcome' => 'dismissed'])->assertOk()->assertJsonPath('data.status', 'dismissed');

    // Then the score is 0 and the note is null
    expect(antiCheatScoreOf($member))->toBe(0)->and(DB::table('risk_signals')->where('id', $signal->id)->value('review_note'))->toBeNull();
});

it('answers the same outcome twice with changed false, one audit row and an untouched review time', function () {
    // Given a signal confirmed once
    signedInStaff();
    [, $signal] = antiCheatReviewSubject();
    antiCheatReview($signal->id, ['outcome' => 'confirmed', 'note' => 'first'])->assertOk();
    $this->travelTo(antiCheatNow()->addMinutes(30));

    // When the same outcome arrives again, from another reviewer and with another note
    signedInStaff(Role::Admin);
    $response = antiCheatReview($signal->id, ['outcome' => 'confirmed', 'note' => 'second'])->assertOk();

    // Then nothing was written
    expect($response->json('meta.changed'))->toBeFalse()->and($response->json('data.review_note'))->toBe('first');
    expect(AuditLog::query()->where('action', 'risk_signal.reviewed')->count())->toBe(1);
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toStartWith('2026-10-21 12:00:00');
});

it('lets the other outcome replace reviewer, time, outcome and note, audited with before and after', function () {
    // Given a signal confirmed by one reviewer
    signedInStaff();
    [$member, $signal] = antiCheatReviewSubject();
    antiCheatReview($signal->id, ['outcome' => 'confirmed', 'note' => 'first'])->assertOk();
    $this->travelTo(antiCheatNow()->addHour());

    // When another reviewer dismisses it
    $second = signedInStaff(Role::Admin);
    antiCheatReview($signal->id, ['outcome' => 'dismissed', 'note' => 'on reflection a household'])->assertOk()->assertJsonPath('meta.changed', true);

    // Then the four columns were replaced, the score follows, and the second audit row carries before and after
    $row = DB::table('risk_signals')->where('id', $signal->id)->first();
    expect($row->outcome)->toBe('dismissed')->and($row->reviewed_by)->toBe($second->id)->and($row->review_note)->toBe('on reflection a household')->and($row->reviewed_at)->toStartWith('2026-10-21 13:00:00');
    expect(antiCheatScoreOf($member))->toBe(0);
    $meta = AuditLog::query()->where('action', 'risk_signal.reviewed')->orderBy('created_at')->orderBy('id')->get()->map->meta;
    expect($meta)->toHaveCount(2)->and(collect($meta)->contains(fn ($m) => $m['before'] === 'confirmed' && $m['after'] === 'dismissed'))->toBeTrue();
});

it('reviews a product-only signal without any score write', function () {
    // Given a signal about a product only (a burst), and some member with a stored score
    signedInStaff();
    $bystander = antiCheatSetScore(User::factory()->create(), 33);
    $signal = RiskSignal::factory()->forProductOnly()->create();
    $writes = [];
    DB::listen(function ($q) use (&$writes) {
        if (str_starts_with(strtolower($q->sql), 'update "users"') || str_contains(strtolower($q->sql), 'update users')) {
            $writes[] = $q->sql;
        }
    });

    // When it is dismissed
    antiCheatReview($signal->id, ['outcome' => 'dismissed'])->assertOk()->assertJsonPath('data.member', null);

    // Then no user row was written and the audit row has a null user
    expect($writes)->toBe([])->and(antiCheatScoreOf($bystander))->toBe(33);
    expect(AuditLog::query()->where('action', 'risk_signal.reviewed')->firstOrFail()->meta['user_id'])->toBeNull();
});

it('reviews the signal of a deleted subject without a score write', function () {
    // Given a signal about a member who has since deleted their account (score left as it was)
    signedInStaff();
    [$member, $signal] = antiCheatReviewSubject();
    DB::table('users')->where('id', $member->id)->update(['deleted_at' => now()]);

    // When it is confirmed
    antiCheatReview($signal->id, ['outcome' => 'confirmed'])->assertOk();

    // Then the signal is reviewed and the deleted member's score was left alone
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('outcome'))->toBe('confirmed')->and(antiCheatScoreOf($member))->toBe(20);
});

it('allows reviewers and admins and refuses a member with the same 403 for an existing id, an unknown id and an invalid body', function () {
    // Given a signal
    [, $signal] = antiCheatReviewSubject();
    $unknown = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // When a member posts to the existing id, an unknown id, and a malformed id, with valid and with garbage bodies
    signedInMember();
    $bodies = [];
    foreach ([$signal->id, $unknown, 'not-a-ulid'] as $id) {
        foreach ([['outcome' => 'confirmed'], ['outcome' => ['x']], [], ['outcome' => 'confirmed', 'note' => ['a']]] as $body) {
            // Then the answer is the one 403 (before the lookup and before the validation)
            $bodies[] = antiCheatReview($id, $body)->assertForbidden()->getContent();
        }
    }
    expect(array_unique($bodies))->toHaveCount(1);
    expect(DB::table('risk_signals')->whereNotNull('reviewed_at')->count())->toBe(0);

    // And both staff roles may review
    foreach ([Role::Reviewer, Role::Admin] as $role) {
        $member = User::factory()->create();
        $other = antiCheatSignal($member, RiskSignalKind::LoginChurn, 20);
        signedInStaff($role);
        antiCheatReview($other->id, ['outcome' => 'dismissed'])->assertOk();
    }
});

it('answers a guest 401', function () {
    // Given a signal and no sign-in
    [, $signal] = antiCheatReviewSubject();

    // When a guest posts
    // Then 401 for an existing and a missing id
    antiCheatReview($signal->id, ['outcome' => 'confirmed'])->assertUnauthorized();
    antiCheatReview('01ARZ3NDEKTSV4RRFFQ69G5FAV', ['outcome' => 'confirmed'])->assertUnauthorized();
});

it('refuses an actor who is no longer an active reviewer or admin when the action is called directly', function (Closure $stale) {
    // Given an action called with an actor model that is stale (the database row has since changed)
    $actor = User::factory()->role(Role::Admin)->create();
    [, $signal] = antiCheatReviewSubject();
    $stale($actor);

    // When the action runs
    // Then it refuses before touching anything
    expect(fn () => app(ReviewRiskSignal::class)->handle($actor, $signal->id, RiskOutcome::Confirmed, null))->toThrow(AuthorizationException::class);
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull()->and(AuditLog::query()->where('action', 'risk_signal.reviewed')->count())->toBe(0);
})->with([
    'demoted' => [fn (User $u) => DB::table('users')->where('id', $u->id)->update(['role' => 'member'])],
    'suspended' => [fn (User $u) => DB::table('users')->where('id', $u->id)->update(['suspended_at' => now()])],
    'deleted' => [fn (User $u) => DB::table('users')->where('id', $u->id)->update(['deleted_at' => now()])],
]);

it('refuses to review a signal about oneself with 403 own_signal and writes nothing', function () {
    // Given a reviewer with a signal about their own account
    $reviewer = signedInStaff();
    antiCheatSetScore($reviewer, 20);
    $signal = antiCheatSignal($reviewer, RiskSignalKind::SharedDevice, 40, 7);

    // When they try to dismiss it
    // Then 403 own_signal, nothing written
    antiCheatReview($signal->id, ['outcome' => 'dismissed'])->assertForbidden()->assertJsonPath('code', 'own_signal');
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull()->and(antiCheatScoreOf($reviewer))->toBe(20);
    expect(AuditLog::query()->where('action', 'risk_signal.reviewed')->count())->toBe(0);
});

it('answers staff 404 for a malformed or unknown id once the body is valid', function () {
    // Given a reviewer
    signedInStaff();

    // When ids that name nothing are posted with a valid body
    // Then 404, never 500
    foreach (['not-a-ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'ab%00cd'] as $id) {
        antiCheatReview($id, ['outcome' => 'confirmed'])->assertNotFound();
    }
});

it('rejects a malformed outcome or note with 422 and writes nothing', function (array $body) {
    // Given a reviewer and an open signal
    signedInStaff();
    [, $signal] = antiCheatReviewSubject();

    // When the body is wrong
    // Then 422 on the offending field and the signal is still open
    antiCheatReview($signal->id, $body)->assertUnprocessable();
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull();
})->with([
    'outcome missing' => [[]],
    'outcome empty' => [['outcome' => '']],
    'outcome unknown' => [['outcome' => 'banned']],
    'outcome open' => [['outcome' => 'open']],
    'outcome wrong case' => [['outcome' => 'Confirmed']],
    'outcome array' => [['outcome' => ['confirmed']]],
    'outcome object' => [['outcome' => ['a' => 'confirmed']]],
    'outcome null' => [['outcome' => null]],
    'outcome number' => [['outcome' => 1]],
    'outcome true' => [['outcome' => true]],
    'note array' => [['outcome' => 'confirmed', 'note' => ['x']]],
    'note object' => [['outcome' => 'confirmed', 'note' => ['a' => 'x']]],
    'note number' => [['outcome' => 'confirmed', 'note' => 5]],
    'note bool' => [['outcome' => 'confirmed', 'note' => true]],
    'note 256 chars' => [['outcome' => 'confirmed', 'note' => str_repeat('a', 256)]],
]);

it('rejects invalid UTF-8 in the note with 422 (sent as a form, since JSON cannot carry it)', function () {
    // Given a reviewer and an open signal
    signedInStaff();
    [, $signal] = antiCheatReviewSubject();

    // When a form post carries malformed bytes
    // Then it is a validation error on the note, never a 500
    $this->post("/api/v1/staff/risk-signals/{$signal->id}/review", ['outcome' => 'confirmed', 'note' => "bad \xC3\x28 bytes"], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('note');
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull();
});

it('rejects a body that is a JSON array, scalar or null with 422', function (string $raw) {
    // Given a reviewer and an open signal
    signedInStaff();
    [, $signal] = antiCheatReviewSubject();

    // When the raw JSON body is not an object
    // Then 422, never 500
    $this->call('POST', "/api/v1/staff/risk-signals/{$signal->id}/review", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)->assertUnprocessable();
})->with(['[]', '[1,2]', 'null', '"confirmed"', '5', 'true']);

it('accepts a 255 character note, strips control characters, stores an empty note as null and ignores extra fields', function () {
    // Given a reviewer and three open signals
    signedInStaff();
    $signals = collect([1, 2, 3])->map(fn () => antiCheatSignal(User::factory()->create(), RiskSignalKind::LoginChurn, 20));

    // When each is reviewed with a maximal note, a note with control characters, and an empty note plus extra fields
    antiCheatReview($signals[0]->id, ['outcome' => 'confirmed', 'note' => str_repeat('a', 255)])->assertOk();
    antiCheatReview($signals[1]->id, ['outcome' => 'confirmed', 'note' => "  tidy\x00 note\x07  "])->assertOk();
    antiCheatReview($signals[2]->id, ['outcome' => 'dismissed', 'note' => '', 'reviewed_by' => 'x', 'weight' => 99, 'user_id' => 'y'])->assertOk();

    // Then the notes are stored cleaned and the extra fields changed nothing
    expect(DB::table('risk_signals')->where('id', $signals[0]->id)->value('review_note'))->toBe(str_repeat('a', 255));
    expect(DB::table('risk_signals')->where('id', $signals[1]->id)->value('review_note'))->toBe('tidy note');
    $third = DB::table('risk_signals')->where('id', $signals[2]->id)->first();
    expect($third->review_note)->toBeNull()->and($third->weight)->toBe(20)->and($third->reviewed_by)->not->toBe('x');
});

it('rolls the whole review back when the audit row cannot be written', function () {
    // Given a reviewer, an open signal and an audit insert that fails
    Exceptions::fake();
    $reviewer = signedInStaff();
    [$member, $signal] = antiCheatReviewSubject();
    AuditLog::creating(fn () => DB::statement('select 1/0'));

    // When the action runs
    // Then it throws, the signal is still open, the score is unchanged
    expect(fn () => app(ReviewRiskSignal::class)->handle($reviewer, $signal->id, RiskOutcome::Confirmed, 'x'))->toThrow(QueryException::class);
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull()->and(antiCheatScoreOf($member))->toBe(20);
});

it('computes the score from both outcomes when a second review lands in the middle of the first', function () {
    // Given a member with two open signals of different kinds (20 and 14 half decayed, 40 and 28 confirmed)
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $member = User::factory()->create();
    $first = antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 7);
    $second = antiCheatSignal($member, RiskSignalKind::DisposableEmail, 28, 7);
    antiCheatSetScore($member, 34);
    $nested = false;

    // When the second review runs from inside the first one's user lock statement
    DB::beforeExecuting(function (string $sql) use (&$nested, $reviewer, $second) {
        if (! $nested && str_contains(strtolower($sql), 'for no key update') && str_contains(strtolower($sql), 'from users')) {
            $nested = true;
            app(ReviewRiskSignal::class)->handle($reviewer, $second->id, RiskOutcome::Confirmed, null);
        }
    });
    app(ReviewRiskSignal::class)->handle($reviewer, $first->id, RiskOutcome::Confirmed, null);

    // Then the stored score is computed from both confirmations
    expect($nested)->toBeTrue()->and(antiCheatScoreOf($member))->toBe(68);
});

it('takes the signal row, then the subject user row, then the audit key share, and never a product row', function () {
    // Given a reviewer and a signal about a member and a product
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $member = User::factory()->create();
    $signal = antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 7, null, ['product_id' => antiCheatLiveProduct()->id]);

    // When the review runs
    $queries = antiCheatQueries(fn () => app(ReviewRiskSignal::class)->handle($reviewer, $signal->id, RiskOutcome::Confirmed, null));

    // Then the order of the interesting statements is: role read, signal lock, user lock, score read, score write, audit insert
    $find = fn (string $needle) => collect($queries)->search(fn ($q) => str_contains($q, $needle));
    $role = $find('select "role" from "users"');
    $signalLock = $find('from "risk_signals" where "risk_signals"."id" = ?');
    $userLock = $find('for no key update');
    $score = $find('from risk_signals where user_id in');
    $write = $find('update users set risk_score');
    $audit = $find('insert into "audit_logs"');
    expect([$role, $signalLock, $userLock, $score, $write, $audit])->each->not->toBeFalse();
    expect($role < $signalLock && $signalLock < $userLock && $userLock < $score && $score < $write && $write < $audit)->toBeTrue();
    // (the product's maker is a plain read; no product row is ever locked)
    expect(collect($queries)->filter(fn ($q) => str_contains($q, 'products') && str_contains($q, ' for ')))->toBeEmpty();
    expect(collect($queries)->filter(fn ($q) => str_contains($q, 'from users') && str_contains($q, 'for update')))->toBeEmpty();
    expect(str_contains($queries[$signalLock], 'for update'))->toBeTrue();
});

it('refuses a signal on the actor own product with 403 own_signal and writes nothing', function () {
    // Given a reviewer who made a live product, and a signal about another member on it
    $reviewer = signedInStaff();
    $product = antiCheatLiveProduct(null, ['maker_id' => $reviewer->id]);
    $signal = RiskSignal::factory()->create(['user_id' => User::factory()->create()->id, 'product_id' => $product->id]);

    // When they review it
    // Then 403 own_signal, nothing written
    antiCheatReview($signal->id, ['outcome' => 'dismissed'])->assertForbidden()->assertJsonPath('code', 'own_signal');
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull();
});

it('lets only admins review a signal about a reviewer or an admin', function (Role $subjectRole) {
    // Given a signal about a staff member
    $subject = User::factory()->role($subjectRole)->create();
    $signal = antiCheatSignal($subject, RiskSignalKind::SharedDevice, 40, 1);

    // When a reviewer tries, then an admin
    signedInStaff(Role::Reviewer);
    antiCheatReview($signal->id, ['outcome' => 'dismissed'])->assertForbidden()->assertJsonPath('code', 'admin_only_signal');
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('reviewed_at'))->toBeNull();
    signedInStaff(Role::Admin);
    antiCheatReview($signal->id, ['outcome' => 'dismissed'])->assertOk();

    // Then only the admin's review stuck
    expect(DB::table('risk_signals')->where('id', $signal->id)->value('outcome'))->toBe('dismissed');
})->with([[Role::Reviewer], [Role::Admin]]);
