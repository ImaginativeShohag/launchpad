<?php

use App\Actions\Email\DispatchEmails;
use App\Enums\EmailType;
use App\Models\EmailMessage;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/MailFixtures.php';

/** $count queued product_live rows, due one second apart from 09:00:00 (so the due order is known), for one member. */
function downQueue(int $count): array
{
    $user = User::factory()->create();
    $product = Product::factory()->live()->create();
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $due = CarbonImmutable::parse('2026-10-21 09:00:00', 'UTC')->addSeconds($i);
        $row = EmailMessage::factory()->forUser($user)->ofType(EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22'])->queued($due)->create();
        $ids[] = $row->id;
    }

    return $ids;
}

/** The row ids in the order the transport accepted them (the Message-ID header carries the id). */
function downAcceptedIds(): array
{
    return collect(app('outbox.accepted'))->map(fn ($m) => explode('@', trim($m->getOriginalMessage()->getHeaders()->get('Message-ID')->getBodyAsString(), '<>'))[0])->all();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));
});

it('loses nothing during an outage and drains the backlog in due order once the provider is back', function () {
    // Given 120 queued rows and a provider that is down until 12:00
    downQueue(120);
    $upAt = CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC');
    outboxTransport(function () use ($upAt) {
        if (Date::now()->lessThan($upAt)) {
            throw new RuntimeException('provider down');
        }
    });
    // When a run happens during the outage
    $outage = app(DispatchEmails::class)->handle();

    // Then the breaker stopped it after exactly five failures, and only those five rows spent an attempt
    expect($outage['stopped'])->toBe('breaker')->and($outage['transient_failures'])->toBe(5)->and($outage['retried'])->toBe(5)->and($outage['sent'])->toBe(0);
    expect(DB::table('email_messages')->where('attempts', '>', 0)->count())->toBe(5)->and(DB::table('email_messages')->where('status', 'queued')->count())->toBe(120);

    // And a later run during the outage does the same: the five retried rows are not due yet, five fresh rows take the hit
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:30:00', 'UTC'));
    $again = app(DispatchEmails::class)->handle();
    expect($again['stopped'])->toBe('breaker')->and($again['retried'])->toBe(5);

    // When the provider is back and runs continue
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:05:00', 'UTC'));
    // The rows each claim round really took: the id list of the claim UPDATE, counted per round
    $claims = [];
    DB::listen(function ($query) use (&$claims) {
        if (str_starts_with(strtolower($query->sql), "update email_messages set status = 'sending'")) {
            $claims[] = count($query->bindings) - 2;
        }
    });
    $dueOrder = DB::table('email_messages')->orderBy('next_attempt_at')->orderBy('id')->pluck('id')->all();
    $recovery = app(DispatchEmails::class)->handle();

    // Then every row is sent, none is lost, and claims were 50 rows at a time
    expect($recovery['stopped'])->toBeNull()->and($recovery['sent'])->toBe(120)->and($recovery['failed'])->toBe(0);
    expect(DB::table('email_messages')->where('status', 'sent')->count())->toBe(120)->and(DB::table('email_messages')->count())->toBe(120);
    expect($claims)->toBe([50, 50, 20]);

    // And the rows went out in due order, oldest first (the ten rows that spent an attempt come after the untouched older ones)
    expect(downAcceptedIds())->toBe($dueOrder);
});

it('releases the unsent claims of a stopped run with their attempts restored', function () {
    // Given 60 due rows and a provider that is down
    downQueue(60);
    outboxTransport(function () {
        throw new RuntimeException('down');
    });

    // When a run is stopped by the breaker
    $result = app(DispatchEmails::class)->handle();

    // Then exactly the five tried rows carry attempt 1 (retried, due in a minute); the other 55 are queued, due, with attempt 0
    expect($result['stopped'])->toBe('breaker');
    expect(DB::table('email_messages')->where('attempts', 1)->count())->toBe(5)->and(DB::table('email_messages')->where('attempts', 0)->count())->toBe(55);
    expect(DB::table('email_messages')->where('status', 'sending')->count())->toBe(0);
});

it('resets the breaker count on a success', function () {
    // Given a provider that fails four times, succeeds once, then fails four more times
    downQueue(9);
    $calls = 0;
    outboxTransport(function () use (&$calls) {
        $calls++;
        if ($calls !== 5) {
            throw new RuntimeException('flaky');
        }
    });

    // When a run happens
    $result = app(DispatchEmails::class)->handle();

    // Then the breaker never trips (never five in a row) and all nine rows were tried
    expect($result['stopped'])->toBeNull()->and($result['sent'])->toBe(1)->and($result['retried'])->toBe(8);
});

it('stops at the time budget, releases the rest and prints stopped: budget', function () {
    // Given 10 due rows and a provider that takes 20 seconds per send
    downQueue(10);
    outboxTransport(function () {
        test()->travel(20)->seconds();
    });

    // When a run happens
    $result = app(DispatchEmails::class)->handle(CarbonImmutable::now('UTC'));

    // Then it sent three (20, 40, 60 seconds), stopped on the budget and gave the rest back untouched
    expect($result['stopped'])->toBe('budget')->and($result['sent'])->toBe(3);
    expect(DB::table('email_messages')->where('status', 'queued')->where('attempts', 0)->count())->toBe(7)->and(DB::table('email_messages')->where('status', 'sending')->count())->toBe(0);
});

it('counts suppressions and permanent failures without tripping the breaker', function () {
    // Given eight opted-out rows and a failing provider
    $user = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $user->id, 'type' => 'product_live', 'enabled' => false, 'updated_at' => now()]);
    $product = Product::factory()->live()->create();
    EmailMessage::factory()->forUser($user)->ofType(EmailType::ProductLive, ['product_id' => $product->id, 'launch_date' => '2026-10-22'])->queued(now()->subMinute())->count(8)->create();
    outboxTransport(function () {
        throw new RuntimeException('down');
    });

    // When a run happens
    $result = app(DispatchEmails::class)->handle();

    // Then all eight are suppressed, the transport was never called, and nothing stopped the run
    expect($result)->toBe(['sent' => 0, 'retried' => 0, 'suppressed' => 8, 'failed' => 0, 'exhausted' => 0, 'transient_failures' => 0, 'stopped' => null]);
});

it('exits non-zero while failing and zero when clean, and a run of only suppressions exits zero', function () {
    // Given one due row and a failing provider
    downQueue(1);
    outboxTransport(function () {
        throw new RuntimeException('down');
    });

    // When the command runs
    $failing = Artisan::call('launchpad:send-emails');

    // Then it exits 1; once the provider is back and the row is due it exits 0
    expect($failing)->toBe(1);
    outboxTransportDo(fn () => null);
    $this->travelTo(now()->addMinutes(2));
    expect(Artisan::call('launchpad:send-emails'))->toBe(0);
    expect(DB::table('email_messages')->where('status', 'sent')->count())->toBe(1);

    // And a run with nothing to do exits 0 as well
    expect(Artisan::call('launchpad:send-emails'))->toBe(0);
});
