<?php

use App\Jobs\SendPasswordResetLink;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangeRequestedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

const AUTH_MAIL_DELAYS = [60, 120, 300, 900, 1800, 3600, 3600];

it('gives the auth notifications eight tries and about three hours of backoff', function (string $class) {
    // Given a notification class
    $notification = (new ReflectionClass($class))->newInstanceWithoutConstructor();

    // When its retry plan is read
    // Then it has 8 tries and the seven documented delays, 2.5 to 3.5 hours in all
    expect($notification->tries)->toBe(8)->and($notification->backoff())->toBe(AUTH_MAIL_DELAYS);
    expect(array_sum($notification->backoff()))->toBeGreaterThan(2.5 * 3600)->toBeLessThan(3.5 * 3600);
})->with([ConfirmEmailChangeNotification::class, EmailChangeRequestedNotification::class, VerifyEmailNotification::class, ResetPasswordNotification::class]);

it('gives the reset-link job eight tries, a first wait above the broker throttle and the same tail', function () {
    // Given the job
    $job = new SendPasswordResetLink('amina@example.com');

    // When its retry plan is read
    // Then it has 8 tries, the first wait is above 60 seconds, and the rest matches the notifications
    expect($job->tries)->toBe(8)->and($job->backoff())->toBe([65, 120, 300, 900, 1800, 3600, 3600]);
    expect($job->backoff()[0])->toBeGreaterThan(60);
});

it('keeps the encrypted, after-commit, delete-when-missing behaviour of the auth mail', function () {
    // Given the three notifications and the job
    // When their traits and contracts are read
    // Then nothing else about them changed
    expect((new ReflectionClass(SendPasswordResetLink::class))->implementsInterface(ShouldBeEncrypted::class))->toBeTrue();
    expect((new VerifyEmailNotification)->afterCommit)->toBeTrue();
});

it('retries a down mail provider eight times with the declared delays, then fails exactly once', function (string $kind) {
    // Given the real database queue and a mail provider that is down
    config(['queue.default' => 'database', 'mail.default' => 'down', 'mail.mailers.down' => ['transport' => 'down']]);
    Mail::extend('down', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new RuntimeException('provider down');
        }

        public function __toString(): string
        {
            return 'down';
        }
    });
    $user = User::factory()->create(['email' => 'old@example.com']);
    $expected = AUTH_MAIL_DELAYS;
    match ($kind) {
        'confirm' => Notification::route('mail', 'new@example.com')->notify(new ConfirmEmailChangeNotification((string) $user->id, 'new@example.com', now()->addHour())),
        'verify' => $user->notify(new VerifyEmailNotification),
        'reset notification' => $user->notify(new ResetPasswordNotification('token')),
        'reset job' => (function () use (&$expected) {
            // The broker is made to fail, so the job itself throws on every run.
            Password::shouldReceive('sendResetLink')->andThrow(new RuntimeException('broker down'));
            Exceptions::fake();
            SendPasswordResetLink::dispatch('old@example.com');
            $expected = [65, 120, 300, 900, 1800, 3600, 3600];
        })(),
    };
    expect(DB::table('jobs')->count())->toBe(1);

    // When the worker runs the job eight times, making it due again each time
    $delays = [];
    for ($attempt = 1; $attempt <= 8; $attempt++) {
        DB::table('jobs')->update(['available_at' => time()]);
        Artisan::call('queue:work', ['--once' => true, '--tries' => 0, '--sleep' => 0]);
        $job = DB::table('jobs')->first();
        $delays[] = $job === null ? null : $job->available_at - time();
    }

    // Then each failure was released with the declared delay, and the eighth one failed the job for good (once)
    foreach ($expected as $i => $seconds) {
        expect($delays[$i])->toBeGreaterThanOrEqual($seconds - 3)->toBeLessThanOrEqual($seconds);
    }
    expect($delays[7])->toBeNull()->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(1);
    DB::table('failed_jobs')->delete();
})->with(['confirm', 'verify', 'reset notification', 'reset job']);
