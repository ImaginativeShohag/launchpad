<?php

use App\Enums\EmailType;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mailer\Exception\TransportException;

require_once __DIR__.'/../../Support/MailFixtures.php';

it('prints the counts of a clean run and exits zero', function () {
    // Given two due rows and a working provider
    outboxTransport(fn () => null);
    outboxReady();
    outboxReady();

    // When the command runs
    $this->artisan('launchpad:send-emails')
        // Then the line has the documented format and the exit code is zero
        ->expectsOutput('sent 2, retried 0, suppressed 0, failed 0')
        ->assertExitCode(0);
    expect(DB::table('email_messages')->where('status', 'sent')->count())->toBe(2);
});

it('exits non-zero and says why it stopped when the provider is down', function () {
    // Given six due rows and a provider that is down
    outboxTransport(function () {
        throw new RuntimeException('down');
    });
    foreach (range(1, 6) as $_) {
        outboxReady();
    }

    // When the command runs
    $this->artisan('launchpad:send-emails')
        // Then it reports the five retries and the breaker, and exits 1
        ->expectsOutput('sent 0, retried 5, suppressed 0, failed 0, stopped: breaker')
        ->assertExitCode(1);
});

it('prints a permanently failed row but exits zero (it is final and logged)', function () {
    // Given a row the mailer cannot render and a row that hard-bounces
    outboxTransport(function () {
        throw new TransportException('x', 550);
    });
    outboxQueued(EmailType::ProductLive, ['nope' => 1]);
    outboxReady();

    // When the command runs
    // Then both are counted as failed and the exit code is zero
    $this->artisan('launchpad:send-emails')->expectsOutput('sent 0, retried 0, suppressed 0, failed 2')->assertExitCode(0);
});

it('exits non-zero when a lease-expired row at its last attempt was failed (lost mail)', function () {
    // Given a sending row on attempt 9 whose lease ended, and a working provider
    outboxTransport(fn () => null);
    $row = outboxReady();
    DB::table('email_messages')->where('id', $row->id)->update(['status' => 'sending', 'attempts' => 9, 'next_attempt_at' => now()->subMinute()]);

    // When the command runs
    // Then it surfaces the lost row and exits 1
    $this->artisan('launchpad:send-emails')->expectsOutput('sent 0, retried 0, suppressed 0, failed 1, exhausted 1')->assertExitCode(1);
    expect(outboxRow($row->id)['status'])->toBe('failed')->and(outboxRow($row->id)['last_error'])->toBe('lease_expired');
});

it('exits zero when everything was suppressed', function () {
    // Given a due row of a member who opted out
    outboxTransport(function () {
        throw new RuntimeException('must not be called');
    });
    $user = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => 'product_live', 'enabled' => false, 'updated_at' => now()]);
    outboxReady($user);

    // When the command runs
    // Then the row is suppressed and the exit code is zero
    $this->artisan('launchpad:send-emails')->expectsOutput('sent 0, retried 0, suppressed 1, failed 0')->assertExitCode(0);
});

it('is scheduled once, every minute, with a 5 minute overlap lock on one server', function () {
    // Given the schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:send-emails'));

    // When the entries are read
    // Then there is exactly one, with the documented protection
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('* * * * *')->and($event->expiresAt)->toBe(5)->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});
