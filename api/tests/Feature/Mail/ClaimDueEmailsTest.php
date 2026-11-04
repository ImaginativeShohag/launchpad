<?php

use App\Actions\Email\ClaimDueEmails;
use App\Models\EmailMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/MailFixtures.php';

function claimNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC');
}

it('marks due rows sending with a lease, counts the attempt and returns them in due order', function () {
    // Given three due rows, created out of order, and a row that is not due yet
    $user = User::factory()->create();
    $late = EmailMessage::factory()->forUser($user)->queued(claimNow()->subMinutes(1))->create();
    $early = EmailMessage::factory()->forUser($user)->queued(claimNow()->subMinutes(30))->create();
    $middle = EmailMessage::factory()->forUser($user)->queued(claimNow()->subMinutes(10))->create();
    $future = EmailMessage::factory()->forUser($user)->queued(claimNow()->addMinute())->create();

    // When a claim runs
    $claims = app(ClaimDueEmails::class)->handle(claimNow(), 50);

    // Then the three are returned oldest due first with attempts 1, marked sending with a 10 minute lease
    expect($claims)->toBe([['id' => $early->id, 'attempts' => 1], ['id' => $middle->id, 'attempts' => 1], ['id' => $late->id, 'attempts' => 1]]);
    foreach ([$early, $middle, $late] as $row) {
        $stored = outboxRow($row->id);
        expect($stored['status'])->toBe('sending')->and((int) $stored['attempts'])->toBe(1)
            ->and(strtotime($stored['next_attempt_at']))->toBe(claimNow()->addMinutes(10)->getTimestamp());
    }

    // And the future row is untouched
    expect(outboxRow($future->id)['status'])->toBe('queued')->and((int) outboxRow($future->id)['attempts'])->toBe(0);
});

it('breaks ties on the due time by id', function () {
    // Given three rows due at the same instant
    $user = User::factory()->create();
    $rows = EmailMessage::factory()->forUser($user)->queued(claimNow()->subMinute())->count(3)->create();

    // When they are claimed
    $ids = array_column(app(ClaimDueEmails::class)->handle(claimNow(), 50), 'id');

    // Then the order is by id
    expect($ids)->toBe($rows->pluck('id')->sort()->values()->all());
});

it('respects the limit and takes the oldest rows first', function () {
    // Given five due rows
    $user = User::factory()->create();
    $rows = collect(range(5, 1))->map(fn (int $minutes) => EmailMessage::factory()->forUser($user)->queued(claimNow()->subMinutes($minutes))->create());

    // When two are claimed
    $claims = app(ClaimDueEmails::class)->handle(claimNow(), 2);

    // Then only the two oldest are claimed and the rest stay queued
    expect(array_column($claims, 'id'))->toBe([$rows[0]->id, $rows[1]->id]);
    expect(DB::table('email_messages')->where('status', 'queued')->count())->toBe(3);
});

it('re-claims an expired lease with the attempt counted again', function () {
    // Given a row whose worker died: sending, attempt 2, lease ended a minute ago
    $row = EmailMessage::factory()->forUser(User::factory()->create())->sending(claimNow()->subMinute(), 2)->create();

    // When a claim runs
    $claims = app(ClaimDueEmails::class)->handle(claimNow(), 50);

    // Then it is taken again as attempt 3
    expect($claims)->toBe([['id' => $row->id, 'attempts' => 3]]);
});

it('leaves a sending row alone while its lease is running', function () {
    // Given a row being sent with a lease that ends in the future
    $row = EmailMessage::factory()->forUser(User::factory()->create())->sending(claimNow()->addMinutes(5), 1)->create();

    // When a claim runs
    // Then nothing is claimed
    expect(app(ClaimDueEmails::class)->handle(claimNow(), 50))->toBe([]);
    expect((int) outboxRow($row->id)['attempts'])->toBe(1);
});

it('fails an expired lease that already used its last attempt instead of claiming it again', function () {
    // Given a row on attempt 9 whose lease expired, and a normal due row
    $user = User::factory()->create();
    $dead = EmailMessage::factory()->forUser($user)->sending(claimNow()->subMinute(), 9)->create();
    $ok = EmailMessage::factory()->forUser($user)->queued(claimNow()->subMinute())->create();

    // When a claim runs
    $claims = ($claimer = app(ClaimDueEmails::class))->handle(claimNow(), 50);

    // Then the dead row is failed with lease_expired and not returned; the other is claimed
    expect(array_column($claims, 'id'))->toBe([$ok->id]);
    $stored = outboxRow($dead->id);
    expect($stored['status'])->toBe('failed')->and($stored['next_attempt_at'])->toBeNull()->and($stored['last_error'])->toBe('lease_expired')->and((int) $stored['attempts'])->toBe(9);

    // And the claim reports how many rows it failed that way (and resets the count on the next call)
    expect($claimer->exhausted)->toBe(1);
    $claimer->handle(claimNow(), 50);
    expect($claimer->exhausted)->toBe(0);
});

it('releases unsent claims with their attempt taken off, only for matching (id, attempts)', function () {
    // Given three claimed rows, one of which another worker re-claimed meanwhile (attempts moved on)
    $user = User::factory()->create();
    $rows = EmailMessage::factory()->forUser($user)->queued(claimNow()->subMinute())->count(3)->create();
    $claims = app(ClaimDueEmails::class)->handle(claimNow(), 50);
    DB::table('email_messages')->where('id', $claims[2]['id'])->update(['attempts' => 2]);

    // When the claims are released
    $released = app(ClaimDueEmails::class)->release($claims, claimNow());

    // Then two are back to queued with attempts 0 and due now; the re-claimed one is untouched
    expect($released)->toBe(2);
    foreach ([0, 1] as $i) {
        $stored = outboxRow($claims[$i]['id']);
        expect($stored['status'])->toBe('queued')->and((int) $stored['attempts'])->toBe(0)->and(strtotime($stored['next_attempt_at']))->toBe(claimNow()->getTimestamp());
    }
    expect(outboxRow($claims[2]['id'])['status'])->toBe('sending')->and((int) outboxRow($claims[2]['id'])['attempts'])->toBe(2);
});

it('never touches terminal rows', function () {
    // Given sent, failed and suppressed rows
    $user = User::factory()->create();
    EmailMessage::factory()->forUser($user)->sent()->create();
    EmailMessage::factory()->forUser($user)->failed()->create();
    EmailMessage::factory()->forUser($user)->suppressed()->create();

    // When a claim runs
    // Then nothing is claimed
    expect(app(ClaimDueEmails::class)->handle(claimNow()->addYear(), 50))->toBe([]);
});

it('picks inside a materialised CTE with FOR UPDATE SKIP LOCKED', function () {
    // Given a due row
    EmailMessage::factory()->forUser(User::factory()->create())->queued(claimNow()->subMinute())->create();
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    // When a claim runs
    app(ClaimDueEmails::class)->handle(claimNow(), 5);

    // Then the pick is the documented statement
    $pick = collect($sql)->first(fn (string $s) => str_contains($s, 'with picked as materialized'));
    expect($pick)->not->toBeNull()->toContain('for update skip locked')->toContain('limit ?');
});
