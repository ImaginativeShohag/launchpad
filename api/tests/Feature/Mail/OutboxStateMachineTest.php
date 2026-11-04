<?php

use App\Actions\Email\ClaimDueEmails;
use App\Actions\Email\DispatchEmails;
use App\Actions\Email\SendEmailMessage;
use App\Enums\EmailType;
use App\Mail\MailFor;
use App\Mail\UnrenderableMail;
use App\Models\EmailMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;

require_once __DIR__.'/../../Support/MailFixtures.php';

/** Sends a freshly claimed row (attempt $attempts) through a transport that throws $throwable. */
function machineFail(Throwable $throwable, int $attempts = 1): array
{
    $row = outboxReadyClaimed(null, $attempts);
    outboxTransportDo(function () use ($throwable) {
        throw $throwable;
    });
    $result = app(SendEmailMessage::class)->handle($row->id, $attempts, CarbonImmutable::now('UTC'));

    return [$result, outboxRow($row->id), $row];
}

beforeEach(function () {
    outboxTransport(fn () => null);
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));
});

it('classifies a failure by its class and structured status code, never by its message', function (Closure $exception, string $expected) {
    // Given a transport that throws the exception
    // When the row is sent
    [$result, $stored] = machineFail($exception());

    // Then the row is retried (queued again) or failed for good, as the class says
    expect($result->outcome)->toBe($expected)->and($stored['status'])->toBe($expected === 'retried' ? 'queued' : 'failed');
    expect($result->transientFailure)->toBe($expected === 'retried');
})->with([
    'RuntimeException' => [fn () => new RuntimeException('boom'), 'retried'],
    'TransportException' => [fn () => new TransportException('connection refused'), 'retried'],
    'TransportException that says 550 permanent' => [fn () => new TransportException('Expected response code "250" but got code "550", with message "550 mailbox unavailable".'), 'retried'],
    'http 503' => [fn () => outboxHttpError(503), 'retried'],
    'http 500' => [fn () => outboxHttpError(500), 'retried'],
    'http 408' => [fn () => outboxHttpError(408), 'retried'],
    'http 429' => [fn () => outboxHttpError(429), 'retried'],
    'http 400' => [fn () => outboxHttpError(400), 'failed'],
    'http 401' => [fn () => outboxHttpError(401), 'failed'],
    'http 422' => [fn () => outboxHttpError(422), 'failed'],
    'http 4xx whose message looks like a 5xx' => [fn () => outboxHttpError(404, 'Server error 503 service unavailable'), 'failed'],
    'http 5xx whose message looks like a 4xx' => [fn () => outboxHttpError(502, '400 Bad Request: invalid recipient'), 'retried'],
    'smtp hard bounce 550' => [fn () => new TransportException('x', 550), 'failed'],
    'smtp mailbox 553' => [fn () => new TransportException('x', 553), 'failed'],
    'smtp 599' => [fn () => new TransportException('x', 599), 'failed'],
    'smtp temporary 451' => [fn () => new TransportException('x', 451), 'retried'],
    'smtp 499' => [fn () => new TransportException('x', 499), 'retried'],
    'smtp no code (dropped connection)' => [fn () => new TransportException('x'), 'retried'],
    'smtp code 5xx look-alike in the message only' => [fn () => new TransportException('550 5.1.1 user unknown', 0), 'retried'],
    'smtp unexpected response 554' => [fn () => new UnexpectedResponseException('x', 554), 'failed'],
    'RfcComplianceException' => [fn () => new RfcComplianceException('bad address'), 'failed'],
    'UnrenderableMail' => [fn () => new UnrenderableMail('bad payload'), 'failed'],
]);

it('schedules the next try exactly +1, 5, 15, 60, 180, 360, 720 and 1440 minutes after each transient failure', function (int $attempt, int $minutes) {
    // Given a row on attempt N with a failing provider, at a frozen clock
    // When it is sent
    [$result, $stored] = machineFail(new RuntimeException('down'), $attempt);

    // Then it is queued again, due exactly the documented wait later, and the attempt count is kept
    expect($result->outcome)->toBe('retried');
    expect($stored['status'])->toBe('queued')->and((int) $stored['attempts'])->toBe($attempt)
        ->and(strtotime($stored['next_attempt_at']))->toBe(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC')->addMinutes($minutes)->getTimestamp());
})->with([[1, 1], [2, 5], [3, 15], [4, 60], [5, 180], [6, 360], [7, 720], [8, 1440]]);

it('fails the ninth transient failure for good and still counts it as a transient failure of the run', function () {
    // Given the ninth attempt of a row with a failing provider
    // When it is sent
    [$result, $stored] = machineFail(new RuntimeException('down'), 9);

    // Then the row is failed, has no due time, and the run still sees a transient failure
    expect($result->outcome)->toBe('failed')->and($result->transientFailure)->toBeTrue();
    expect($stored['status'])->toBe('failed')->and($stored['next_attempt_at'])->toBeNull();
});

it('records the class name and a redacted, bounded error, never an address', function () {
    // Given a provider error whose message holds an address and a very long tail
    $message = 'Cannot send to a@b.com and "Name" <secret.person@example.org>: '.str_repeat('x', 900);

    // When the row is sent
    [, $stored] = machineFail(new RuntimeException($message));

    // Then last_error starts with the class, hides the addresses and has at most 500 characters
    expect($stored['last_error'])->toStartWith('RuntimeException: Cannot send to [email]')->not->toContain('a@b.com')->not->toContain('secret.person')
        ->and(mb_strlen($stored['last_error']))->toBeLessThanOrEqual(500);
});

it('never loses the row: after any failure there is exactly one row in a legal state', function (Throwable $throwable) {
    // Given a failing provider
    // When one row is sent
    [, , $row] = machineFail($throwable);

    // Then the table still holds that one row, in a state the CHECKs allow
    expect(DB::table('email_messages')->where('id', $row->id)->count())->toBe(1)->and(DB::table('email_messages')->count())->toBe(1);
    expect(outboxRow($row->id)['status'])->toBeIn(['queued', 'failed']);
})->with([[new RuntimeException('x')], [new Error('fatal')], [new TypeError('t')]]);

it('logs a redacted warning for a send failure instead of reporting the raw exception, and does not rethrow', function () {
    // Given a failing provider whose message echoes the recipient, and spied logs and exception handler
    Exceptions::fake();
    Log::spy();
    $user = User::factory()->create(['email' => 'private.person@example.com']);
    $row = outboxReadyClaimed($user);
    outboxTransportDo(function () {
        throw new RuntimeException('Cannot deliver to private.person@example.com');
    });

    // When the row is sent
    $result = app(SendEmailMessage::class)->handle($row->id, 1, CarbonImmutable::now('UTC'));

    // Then nothing was reported, one warning was logged with the id, the attempt and a redacted summary, and no address
    expect($result->outcome)->toBe('retried');
    Exceptions::assertNothingReported();
    Log::shouldHaveReceived('warning')->once()->with('outbox.send_failed', Mockery::on(function (array $context) use ($row) {
        return $context['id'] === $row->id && $context['attempt'] === 1
            && str_starts_with($context['error'], 'RuntimeException: Cannot deliver to [email]')
            && ! str_contains(json_encode($context), 'private.person')
            && array_keys($context) === ['id', 'attempt', 'error'];
    }));
});

it('still reports a render fault', function () {
    // Given a row the mailer cannot render
    Exceptions::fake();
    $row = outboxClaimed(outboxQueued(EmailType::ProductLive, ['nope' => 1]));

    // When it is sent
    app(SendEmailMessage::class)->handle($row->id, 1, CarbonImmutable::now('UTC'));

    // Then the unrenderable mail was reported
    Exceptions::assertReported(UnrenderableMail::class);
});

it('does not count a hard bounce toward the breaker', function () {
    // Given eight rows that all hard-bounce (550)
    foreach (range(1, 8) as $_) {
        outboxReady();
    }
    outboxTransportDo(function () {
        throw new TransportException('x', 550);
    });

    // When a run happens
    $result = app(DispatchEmails::class)->handle();

    // Then all eight failed for good, no transient failure was counted and the breaker did not trip
    expect($result['failed'])->toBe(8)->and($result['transient_failures'])->toBe(0)->and($result['stopped'])->toBeNull();
});

it('leaves a crashed send resendable: the row stays sending, and after the lease it is claimed again with the same Message-ID', function () {
    // Given a row claimed and accepted by the provider, but the worker died before recording (we simulate: nothing is recorded)
    $row = outboxReadyClaimed();
    $domain = substr(strrchr((string) config('mail.from.address'), '@'), 1);
    Mail::mailer()->to('member@example.com')->send(
        MailFor::make(EmailMessage::query()->findOrFail($row->id))
            ->withSymfonyMessage(fn ($email) => $email->getHeaders()->addIdHeader('Message-ID', $row->id.'@'.$domain)),
    );
    expect(outboxAccepted())->toBe(1);
    expect(outboxRow($row->id)['status'])->toBe('sending');

    // When the lease passes and the next run claims and sends it
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:11:00', 'UTC'));
    $claims = app(ClaimDueEmails::class)->handle(CarbonImmutable::now('UTC'), 50);
    $result = app(SendEmailMessage::class)->handle($claims[0]['id'], $claims[0]['attempts'], CarbonImmutable::now('UTC'));

    // Then it is sent a second time (at-least-once) as attempt 2, with the same Message-ID header as an id the client can collapse
    expect($claims)->toBe([['id' => $row->id, 'attempts' => 2]])->and($result->outcome)->toBe('sent');
    expect(outboxRow($row->id)['status'])->toBe('sent')->and((int) outboxRow($row->id)['attempts'])->toBe(2);
    expect(outboxAccepted())->toBe(2);
    [$first, $second] = [app('outbox.accepted')[0]->getOriginalMessage(), app('outbox.accepted')[1]->getOriginalMessage()];
    expect($second->getHeaders()->get('Message-ID')->getBodyAsString())->toBe($first->getHeaders()->get('Message-ID')->getBodyAsString())->toBe('<'.$row->id.'@'.$domain.'>');
});
