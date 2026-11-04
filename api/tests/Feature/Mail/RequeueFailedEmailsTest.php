<?php

use App\Actions\Notification\Reconcile;
use App\Enums\EmailType;
use App\Models\EmailMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/MailFixtures.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    $this->user = User::factory()->create();
});

/** A failed row whose last change (the failure) was $hoursAgo hours ago. */
function requeueFailed(User $user, int $hoursAgo, EmailType $type = EmailType::ReviewOutcome): EmailMessage
{
    $at = CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC')->subHours($hoursAgo);

    return EmailMessage::factory()->forUser($user)->failed('RuntimeException: boom')->ofType($type, ['a' => 'b'])->create(['updated_at' => $at]);
}

it('puts recent failed rows back in the queue, attempts 0, due now, and prints the count', function () {
    // Given a recent and an old failed row, and a sent row
    $recent = requeueFailed($this->user, 2);
    $old = requeueFailed($this->user, 60);
    $sent = EmailMessage::factory()->forUser($this->user)->sent()->create();

    // When the command runs with the default 48h window
    $this->artisan('launchpad:requeue-failed-emails')->expectsOutput('requeued 1')->assertExitCode(0);

    // Then only the recent failure is queued again
    $stored = outboxRow($recent->id);
    expect($stored['status'])->toBe('queued')->and((int) $stored['attempts'])->toBe(0)->and($stored['last_error'])->toBeNull()
        ->and(strtotime($stored['next_attempt_at']))->toBe(CarbonImmutable::now('UTC')->getTimestamp());
    expect(outboxRow($old->id)['status'])->toBe('failed')->and(outboxRow($sent->id)['status'])->toBe('sent');
});

it('is idempotent', function () {
    // Given a failed row requeued once
    requeueFailed($this->user, 1);
    $this->artisan('launchpad:requeue-failed-emails')->expectsOutput('requeued 1');

    // When the command runs again
    // Then there is nothing left to requeue
    $this->artisan('launchpad:requeue-failed-emails')->expectsOutput('requeued 0')->assertExitCode(0);
});

it('honours --since and --type', function () {
    // Given failed rows of two types, one of them 60 hours old
    $review = requeueFailed($this->user, 2);
    $live = requeueFailed($this->user, 2, EmailType::ProductLive);
    $old = requeueFailed($this->user, 60, EmailType::ProductLive);

    // When only product_live rows of the last 7 days are requeued
    $this->artisan('launchpad:requeue-failed-emails --since=7d --type=product_live')->expectsOutput('requeued 2');

    // Then both product_live rows moved and the review row did not
    expect(outboxRow($live->id)['status'])->toBe('queued')->and(outboxRow($old->id)['status'])->toBe('queued')->and(outboxRow($review->id)['status'])->toBe('failed');
});

it('refuses a bad --since or --type without touching anything', function (string $args) {
    // Given a failed row
    $row = requeueFailed($this->user, 1);

    // When the command is called with bad options
    // Then it refuses and the row is untouched
    $this->artisan('launchpad:requeue-failed-emails '.$args)->assertExitCode(2);
    expect(outboxRow($row->id)['status'])->toBe('failed');
})->with(['--since=abc', '--since=0h', '--since=-5h', '--since=48', '--type=bogus', '--type=']);

it('works in chunks with the materialised CTE and SKIP LOCKED', function () {
    // Given 1100 failed rows
    $rows = [];
    for ($i = 0; $i < 1100; $i++) {
        $rows[] = [
            'id' => strtolower((string) Str::ulid()), 'user_id' => $this->user->id, 'type' => 'review_outcome', 'idempotency_key' => "rq:{$i}",
            'to_email' => 'a@example.com', 'payload' => '{}', 'status' => 'failed', 'attempts' => 9, 'next_attempt_at' => null, 'last_error' => 'x', 'provider_message_id' => null,
            'sent_at' => null, 'created_at' => '2026-10-21 11:00:00+00', 'updated_at' => '2026-10-21 11:00:00+00',
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('email_messages')->insert($chunk);
    }
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });

    // When they are requeued
    $this->artisan('launchpad:requeue-failed-emails')->expectsOutput('requeued 1100');

    // Then three chunk statements ran (500, 500, 100), each a materialised CTE with SKIP LOCKED, and nothing stays failed
    $chunks = array_filter($sql, fn (string $s) => str_contains($s, 'with picked as materialized'));
    expect($chunks)->toHaveCount(3);
    foreach ($chunks as $statement) {
        expect($statement)->toContain('for update skip locked');
    }
    expect(DB::table('email_messages')->where('status', 'failed')->count())->toBe(0);
});

it('lets the next send run deliver a requeued row', function () {
    // Given a failed row of a live product mail, a working provider, and a requeue
    outboxTransport(fn () => null);
    $row = outboxReady($this->user);
    DB::table('email_messages')->where('id', $row->id)->update(['status' => 'failed', 'next_attempt_at' => null, 'attempts' => 9, 'updated_at' => now()]);
    $this->artisan('launchpad:requeue-failed-emails')->expectsOutput('requeued 1');

    // When the sender runs
    $this->artisan('launchpad:send-emails')->expectsOutput('sent 1, retried 0, suppressed 0, failed 0')->assertExitCode(0);

    // Then it was sent
    expect(outboxRow($row->id)['status'])->toBe('sent');
});

it('is not scheduled', function () {
    // Given the schedule
    // When it is searched for the manual command
    // Then it is absent
    $found = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'requeue-failed-emails'));
    expect($found)->toHaveCount(0);
});

it('keeps the future email retention window at least the sweep window plus a day', function () {
    // Given the sweep window and the smallest email_messages retention the plan allows (30 days)
    // When they are compared
    // Then the sweep (plus a day of slack) fits inside the retention floor, so a purged sent row can never be re-queued by Reconcile
    expect(Reconcile::MIN_EMAIL_RETENTION_HOURS)->toBe(Reconcile::WINDOW_HOURS + 24);
    expect(30 * 24)->toBeGreaterThanOrEqual(Reconcile::MIN_EMAIL_RETENTION_HOURS);
});
