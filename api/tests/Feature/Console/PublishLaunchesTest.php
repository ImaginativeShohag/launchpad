<?php

use App\Actions\Product\PublishLaunches;
use App\Actions\Product\WithdrawProduct;
use App\Enums\ProductStatus;
use App\Events\ProductWentLive;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-20 00:00:05 UTC')));
afterEach(fn () => AuditLog::flushEventListeners());

it('publishes every scheduled product whose launch day has begun, catching up missed days', function () {
    // Given products scheduled for today, for three days ago (a missed run) and for tomorrow
    Event::fake([ProductWentLive::class]);
    $today = Product::factory()->scheduled('2026-10-20')->create();
    $missed = Product::factory()->scheduled('2026-10-17')->create();
    $tomorrow = Product::factory()->scheduled('2026-10-21')->create();

    // When the command runs
    $this->artisan('launchpad:publish-launches')->expectsOutput('Published 2 product(s).')->assertSuccessful();

    // Then today's and the missed one are live with launched_at set, tomorrow's is untouched
    foreach ([$today, $missed] as $product) {
        $fresh = $product->fresh();
        expect($fresh->status)->toBe(ProductStatus::Live)->and($fresh->launched_at->utc()->toDateTimeString())->toBe('2026-10-20 00:00:05');
        Event::assertDispatched(ProductWentLive::class, fn (ProductWentLive $e) => $e->productId === $product->id && $e->actorId === null);
    }
    expect($tomorrow->fresh()->status)->toBe(ProductStatus::Scheduled)->and($tomorrow->fresh()->launched_at)->toBeNull();
    expect(Product::visible()->pluck('id')->all())->toContain($today->id, $missed->id)->not->toContain($tomorrow->id);
});

it('audits each launch without an actor and with ids and the day only', function () {
    // Given a scheduled product due today
    $product = Product::factory()->scheduled('2026-10-20')->create();

    // When the scheduler runs
    app(PublishLaunches::class)->handle();

    // Then one audit row names the product, no actor, and the launch day
    $log = AuditLog::query()->where('action', 'product.went_live')->firstOrFail();
    expect($log->actor_id)->toBeNull()->and($log->subject_id)->toBe($product->id);
    expect($log->meta)->toEqual(['launch_date' => '2026-10-20']);
});

it('is idempotent: a second run changes nothing and fires nothing', function () {
    // Given a product that has already been published
    Event::fake([ProductWentLive::class]);
    Product::factory()->scheduled('2026-10-20')->create();
    expect(app(PublishLaunches::class)->handle())->toBe(1);

    // When the scheduler runs again
    $second = app(PublishLaunches::class)->handle();

    // Then nothing is published twice
    expect($second)->toBe(0);
    expect(AuditLog::query()->where('action', 'product.went_live')->count())->toBe(1);
    Event::assertDispatchedTimes(ProductWentLive::class, 1);
});

it('ignores every product that is not scheduled', function (string $state) {
    // Given a product in another status (only live and taken-down carry a launch day, the schema forbids it elsewhere)
    $product = Product::factory()->{$state}()->create();
    $before = $product->fresh()->only(['status', 'launched_at']);

    // When the scheduler runs
    expect(app(PublishLaunches::class)->handle())->toBe(0);

    // Then it is untouched
    expect($product->fresh()->only(['status', 'launched_at']))->toEqual($before);
})->with(['complete', 'pending', 'approved', 'live', 'rejected', 'changesRequested', 'takenDown']);

it('switches at exactly 00:00:00 UTC', function () {
    // Given a product scheduled for the 21st and a clock one second before midnight UTC
    $product = Product::factory()->scheduled('2026-10-21')->create();
    $this->travelTo(new DateTimeImmutable('2026-10-20 23:59:59 UTC'));

    // When the scheduler runs before and at midnight
    $before = app(PublishLaunches::class)->handle();
    $this->travelTo(new DateTimeImmutable('2026-10-21 00:00:00 UTC'));
    $at = app(PublishLaunches::class)->handle();

    // Then it goes live at midnight, not before
    expect($before)->toBe(0)->and($at)->toBe(1)->and($product->fresh()->status)->toBe(ProductStatus::Live);
});

it('works through more than one batch', function () {
    // Given five due products and a batch size of two
    Product::factory()->scheduled('2026-10-20')->count(5)->create();

    // When the scheduler runs with that batch size
    $count = app()->make(PublishLaunches::class, ['batch' => 2])->handle();

    // Then all five are published exactly once
    expect($count)->toBe(5)->and(Product::query()->where('status', 'live')->count())->toBe(5);
});

it('keeps publishing the others when one product fails, even on a SQL error (savepoint)', function () {
    // Given three due products and an audit insert that fails with a SQL error for the middle one
    Exceptions::fake();
    $ids = Product::factory()->scheduled('2026-10-20')->count(3)->create()->pluck('id')->sort()->values();
    $bad = $ids[1];
    AuditLog::creating(function (AuditLog $log) use ($bad) {
        if ($log->subject_id === $bad) {
            DB::statement('select 1/0');
        }
    });

    // When the scheduler runs
    $count = app(PublishLaunches::class)->handle();

    // Then the other two are live, the bad one stays scheduled for the next minute, and the failure was reported
    expect($count)->toBe(2);
    expect(Product::query()->whereKey($bad)->value('status'))->toBe(ProductStatus::Scheduled);
    expect(Product::query()->where('status', 'live')->pluck('id')->sort()->values()->all())->toBe([$ids[0], $ids[2]]);
    Exceptions::assertReported(QueryException::class);
});

it('does not publish a product the founder withdrew before the run', function () {
    // Given a scheduled product that is withdrawn (the founder won the race for the row lock)
    $product = Product::factory()->scheduled('2026-10-20')->create();
    app(WithdrawProduct::class)->handle(User::query()->findOrFail($product->maker_id), $product);

    // When the scheduler runs
    // Then it finds nothing
    expect(app(PublishLaunches::class)->handle())->toBe(0);
    expect($product->fresh()->status)->toBe(ProductStatus::Draft);
});

it('selects due rows in id order and skips rows another transaction holds', function () {
    // Given a recorder of queries and one due product
    Product::factory()->scheduled('2026-10-20')->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    // When the scheduler runs
    app(PublishLaunches::class)->handle();

    // Then the selection orders by id and uses SKIP LOCKED (it must never queue behind a founder)
    $select = collect($queries)->first(fn ($sql) => str_contains($sql, 'for update'));
    expect($select)->toContain('order by "id" asc')->toContain('skip locked')->toContain('"status" = ?');
});

it('registers the command to run every minute, without overlapping, on one server', function () {
    // Given the application's schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:publish-launches'));

    // When it is inspected
    // Then there is exactly one entry, every minute, protected against overlap and duplicate servers
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('* * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});

it('fires its event only after the transaction commits', function () {
    // Given the event class
    // When its contract is inspected
    // Then it is dispatched after commit and carries ids only
    expect(new ProductWentLive('p1'))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect(array_keys(get_object_vars(new ProductWentLive('p1'))))->toBe(['productId', 'actorId']);
});

it('fires the event once per published product and not for a failed one, with a real listener', function () {
    // Given a real listener (no Event::fake), three due products and an audit insert that fails for the middle one
    Exceptions::fake();
    $seen = [];
    Event::listen(ProductWentLive::class, function (ProductWentLive $event) use (&$seen) {
        $seen[] = $event->productId;
    });
    $ids = Product::factory()->scheduled('2026-10-20')->count(3)->create()->pluck('id')->sort()->values();
    $bad = $ids[1];
    AuditLog::creating(function (AuditLog $log) use ($bad) {
        if ($log->subject_id === $bad) {
            DB::statement('select 1/0');
        }
    });

    // When the scheduler runs
    app(PublishLaunches::class)->handle();

    // Then the listener ran once for each published product, after the batch committed, and never for the failed one
    expect($seen)->toHaveCount(2)->and($seen)->toEqualCanonicalizing([$ids[0], $ids[2]]);
});

it('exits non-zero and says how many failed when a product cannot be published', function () {
    // Given two due products and an audit insert that fails for one of them
    Exceptions::fake();
    $ids = Product::factory()->scheduled('2026-10-20')->count(2)->create()->pluck('id')->sort()->values();
    AuditLog::creating(function (AuditLog $log) use ($ids) {
        if ($log->subject_id === $ids[0]) {
            DB::statement('select 1/0');
        }
    });

    // When the command runs
    $this->artisan('launchpad:publish-launches')
        ->expectsOutput('Published 1 product(s).')
        ->expectsOutput('1 product(s) failed and stay scheduled for the next run.')
        ->assertFailed();
});

it('keeps going when a listener throws after the batch committed', function () {
    // Given a listener that always throws, and five due products in batches of two
    Exceptions::fake();
    Event::listen(ProductWentLive::class, function () {
        throw new RuntimeException('listener broke');
    });
    Product::factory()->scheduled('2026-10-20')->count(5)->create();

    // When the scheduler runs
    app()->make(PublishLaunches::class, ['batch' => 2])->handle();

    // Then every product is live (the data was committed) and the failures were reported, not thrown
    expect(Product::query()->where('status', 'live')->count())->toBe(5);
    Exceptions::assertReported(RuntimeException::class);
});

it('does not publish a product that was moved to a later day or restored after a takedown again', function () {
    // Given a scheduled product re-dated to tomorrow, and a restored (live) product that already launched
    $moved = Product::factory()->scheduled('2026-10-21')->create();
    $live = Product::factory()->live('2026-10-19')->create();
    $launchedAt = $live->fresh()->launched_at;

    // When the scheduler runs
    $count = app(PublishLaunches::class)->handle();

    // Then neither is touched
    expect($count)->toBe(0)->and($moved->fresh()->status)->toBe(ProductStatus::Scheduled);
    expect($live->fresh()->launched_at->equalTo($launchedAt))->toBeTrue();
    expect(AuditLog::query()->where('action', 'product.went_live')->count())->toBe(0);
});

it('holds the launch of a suspended or deleted maker and publishes it once the account is active again', function (string $state) {
    // Given a due scheduled product whose maker is not active
    $maker = User::factory()->state($state === 'deleted' ? ['deleted_at' => now()] : ['suspended_at' => now()])->create();
    $product = Product::factory()->scheduled('2026-10-20')->create(['maker_id' => $maker->id]);

    // When the scheduler runs
    $held = app(PublishLaunches::class)->handle();

    // Then it stays scheduled; after the account is active again the next run publishes it
    expect($held)->toBe(0)->and($product->fresh()->status)->toBe(ProductStatus::Scheduled);
    DB::table('users')->where('id', $maker->id)->update(['suspended_at' => null, 'deleted_at' => null]);
    expect(app(PublishLaunches::class)->handle())->toBe(1)->and($product->fresh()->status)->toBe(ProductStatus::Live);
})->with(['suspended', 'deleted']);
