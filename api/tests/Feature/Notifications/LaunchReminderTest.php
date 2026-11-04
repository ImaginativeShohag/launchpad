<?php

use App\Actions\Email\QueueLaunchReminders;
use App\Actions\Notification\NotificationDraft;
use App\Actions\Notification\RecordNotification;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/MailFixtures.php';
require_once __DIR__.'/../../Support/RetentionFixtures.php';

/** Today 07:00 UTC; the products below launch tomorrow, 2026-10-21. */
function reminderAt(string $time = '2026-10-20 07:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

function reminderProduct(string $date = '2026-10-21', array $attributes = [], ?User $maker = null): Product
{
    return Product::factory()->scheduled($date)->create(['maker_id' => ($maker ?? User::factory()->create())->id, ...$attributes]);
}

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-20 07:00:00 UTC')));

it('creates one bell row and one mail per product and launch date, however often it runs', function () {
    // Given a scheduled product launching tomorrow with a verified maker
    $maker = User::factory()->create();
    $product = reminderProduct('2026-10-21', ['name' => 'Acme'], $maker);

    // When the job runs once, and nine more times
    $first = app(QueueLaunchReminders::class)->handle(reminderAt());
    $later = [];
    foreach (range(1, 9) as $i) {
        $later[] = app(QueueLaunchReminders::class)->handle(reminderAt('2026-10-20 07:00:00')->addMinutes(10 * $i));
    }

    // Then exactly one bell row and one outbox row exist, and the re-runs created nothing
    expect($first)->toBe(['found' => 1, 'created' => 1, 'failed' => 0]);
    foreach ($later as $result) {
        expect($result)->toBe(['found' => 1, 'created' => 0, 'failed' => 0]);
    }
    [$bell, $mail] = producerRows();
    expect($bell)->toHaveCount(1)->and($mail)->toHaveCount(1);
    expect($bell[0]['user_id'])->toBe($maker->id)->and($bell[0]['type'])->toBe('launch_reminder')->and($bell[0]['dedupe_key'])->toBe('launch_reminder:'.$product->id.':2026-10-21');
    expect(json_decode($bell[0]['data'], true))->toEqual([
        'message_key' => 'notifications.launch_reminder', 'product_id' => $product->id, 'params' => ['product_name' => 'Acme'], 'href' => '/me/products/'.$product->id,
    ]);
    expect($mail[0]['type'])->toBe('launch_reminder')->and($mail[0]['idempotency_key'])->toBe('launch_reminder:'.$product->id.':2026-10-21:'.$maker->id)->and($mail[0]['status'])->toBe('queued');
    expect(json_decode($mail[0]['payload'], true))->toEqual(['product_id' => $product->id, 'launch_date' => '2026-10-21']);
});

it('makes one more reminder when a product is moved to another date, and keeps the old one', function () {
    // Given a product that already got its reminder for the 21st
    $product = reminderProduct('2026-10-21');
    app(QueueLaunchReminders::class)->handle(reminderAt());

    // When the maker moves it to the 22nd and the next day's window opens
    DB::table('products')->where('id', $product->id)->update(['launch_date' => '2026-10-22']);
    $quiet = app(QueueLaunchReminders::class)->handle(reminderAt());
    $next = app(QueueLaunchReminders::class)->handle(reminderAt('2026-10-21 07:00:00'));

    // Then today's run found nothing for it, the next day's run created the second reminder, and both rows remain
    expect($quiet)->toBe(['found' => 0, 'created' => 0, 'failed' => 0])->and($next)->toBe(['found' => 1, 'created' => 1, 'failed' => 0]);
    expect(DB::table('notifications')->orderBy('dedupe_key')->pluck('dedupe_key')->all())->toBe([
        'launch_reminder:'.$product->id.':2026-10-21', 'launch_reminder:'.$product->id.':2026-10-22',
    ]);
    expect(DB::table('email_messages')->count())->toBe(2);
});

it('keeps the reminder of a product that was moved away after it was sent', function () {
    // Given a reminder sent for the 21st and the product then moved to the 25th
    $product = reminderProduct('2026-10-21');
    app(QueueLaunchReminders::class)->handle(reminderAt());
    DB::table('products')->where('id', $product->id)->update(['launch_date' => '2026-10-25']);

    // When the job runs again
    $result = app(QueueLaunchReminders::class)->handle(reminderAt('2026-10-20 12:00:00'));

    // Then nothing new is created and the sent reminder is untouched
    expect($result['created'])->toBe(0)->and(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 1]);
});

it('only runs inside the window: from the send time until midnight', function (string $now, bool $runs) {
    // Given a product launching on the 21st
    reminderProduct('2026-10-21');
    $action = app(QueueLaunchReminders::class);

    // When the job runs at the given instant
    $result = $action->handle(CarbonImmutable::parse($now, 'UTC'));

    // Then it either did the work or stayed out of the way
    expect($action->outsideWindow)->toBe(! $runs)->and($result['created'])->toBe($runs ? 1 : 0);
    expect(DB::table('notifications')->count())->toBe($runs ? 1 : 0);
})->with([
    'a minute before the send time' => ['2026-10-20 06:59:59', false],
    'at the send time' => ['2026-10-20 07:00:00', true],
    'late evening' => ['2026-10-20 23:59:59', true],
    'on the launch day itself, the window for the next day just opened' => ['2026-10-21 00:00:00', false],
]);

it('reads the send time live and falls back to 07:00 with a warning for a malformed value', function () {
    // Given a product launching tomorrow and a send time moved to 06:00
    reminderProduct('2026-10-21');
    retentionStoreRaw(SettingKey::DigestDailySendAtUtc, '06:00');

    // When the job runs at 06:30
    $early = app(QueueLaunchReminders::class)->handle(reminderAt('2026-10-20 06:30:00'));

    // Then the new time is honoured
    expect($early['created'])->toBe(1);

    // And a malformed stored time falls back to 07:00, warning once per run with the key only
    DB::table('notifications')->delete();
    DB::table('email_messages')->delete();
    Log::spy();
    foreach (['7:00', '25:00', 'x'] as $bad) {
        retentionStoreRaw(SettingKey::DigestDailySendAtUtc, $bad);
        $before = app(QueueLaunchReminders::class)->handle(reminderAt('2026-10-20 06:59:00'));
        $after = app(QueueLaunchReminders::class)->handle(reminderAt('2026-10-20 07:00:00'));
        expect($before['created'])->toBe(0)->and($after['created'])->toBe(1);
        DB::table('notifications')->delete();
        DB::table('email_messages')->delete();
    }
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => $c === ['key' => 'digest.daily_send_at_utc'])->times(6);
});

it('reminds only for scheduled, undeleted products launching tomorrow', function () {
    // Given one eligible product and one of every other kind
    $eligible = reminderProduct('2026-10-21');
    Product::factory()->approved()->create();
    Product::factory()->pending()->create();
    Product::factory()->live('2026-10-21')->create();
    Product::factory()->takenDown()->create();
    Product::factory()->create();
    reminderProduct('2026-10-22');
    reminderProduct('2026-10-20');
    $trashed = reminderProduct('2026-10-21');
    DB::table('products')->where('id', $trashed->id)->update(['deleted_at' => now()]);

    // When the job runs
    $result = app(QueueLaunchReminders::class)->handle(reminderAt());

    // Then only the eligible product is reminded
    expect($result)->toBe(['found' => 1, 'created' => 1, 'failed' => 0]);
    expect(DB::table('notifications')->pluck('dedupe_key')->all())->toBe(['launch_reminder:'.$eligible->id.':2026-10-21']);
});

it('skips a suspended or deleted maker and gives an unverified maker the bell only', function () {
    // Given launches of a suspended maker, a deleted maker and an unverified maker
    reminderProduct('2026-10-21', [], User::factory()->suspended()->create());
    $deleted = User::factory()->create();
    reminderProduct('2026-10-21', [], $deleted);
    DB::table('users')->where('id', $deleted->id)->update(['deleted_at' => now()]);
    $unverified = User::factory()->unverified()->create();
    reminderProduct('2026-10-21', [], $unverified);

    // When the job runs
    $result = app(QueueLaunchReminders::class)->handle(reminderAt());

    // Then only the unverified maker is reminded, in the bell, with no mail
    expect($result)->toBe(['found' => 1, 'created' => 1, 'failed' => 0]);
    expect(DB::table('notifications')->pluck('user_id')->all())->toBe([$unverified->id])->and(DB::table('email_messages')->count())->toBe(0);
});

it('counts a failing product, reports it, and still reminds the others', function () {
    // Given three launches and a recorder that throws for one
    $products = collect(range(1, 3))->map(fn () => reminderProduct('2026-10-21'))->sortBy('id')->values();
    $bad = $products[1]->id;
    app()->bind(RecordNotification::class, fn () => new class($bad) extends RecordNotification
    {
        public function __construct(private string $bad) {}

        public function handle(NotificationDraft $draft): bool
        {
            if (($draft->data['product_id'] ?? null) === $this->bad) {
                throw new RuntimeException('boom');
            }

            return parent::handle($draft);
        }
    });
    Exceptions::fake();

    // When the job runs
    $result = app(QueueLaunchReminders::class)->handle(reminderAt());

    // Then the other two were created and the failure was reported and counted
    expect($result)->toBe(['found' => 3, 'created' => 2, 'failed' => 1]);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');
    expect(DB::table('notifications')->pluck('dedupe_key')->sort()->values()->all())->toBe(collect([$products[0], $products[2]])->map(fn ($p) => 'launch_reminder:'.$p->id.':2026-10-21')->sort()->values()->all());
});

it('walks the products by id in chunks with a flat number of product queries', function () {
    // Given five launching products and a chunk size of two
    foreach (range(1, 5) as $i) {
        reminderProduct('2026-10-21');
    }
    $action = new QueueLaunchReminders(app(RecordNotification::class), app(Settings::class), 2);

    // When the job runs
    $selects = retentionCaptureSql(fn () => $result = $action->handle(reminderAt()), 'select * from "products"');

    // Then three product queries ran (2 + 2 + 1), none without a limit, and every product was reminded
    expect($selects)->toHaveCount(3);
    foreach ($selects as $sql) {
        expect($sql)->toContain('order by "id" asc limit 2');
    }
    expect(DB::table('notifications')->count())->toBe(5);
});
