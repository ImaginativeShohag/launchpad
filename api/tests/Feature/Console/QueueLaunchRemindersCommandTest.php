<?php

use App\Actions\Notification\NotificationDraft;
use App\Actions\Notification\RecordNotification;
use App\Models\Product;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

it('prints the counts and exits zero when it reminded someone', function () {
    // Given a product launching tomorrow and the clock after the send time
    $this->travelTo(new DateTimeImmutable('2026-10-20 09:00:00 UTC'));
    Product::factory()->scheduled('2026-10-21')->create(['maker_id' => User::factory()->create()->id]);

    // When the command runs
    // Then it reports one reminder created and exits zero, and a second run creates none
    $this->artisan('launchpad:queue-launch-reminders')->expectsOutput('found 1, created 1, failed 0')->assertExitCode(0);
    $this->artisan('launchpad:queue-launch-reminders')->expectsOutput('found 1, created 0, failed 0')->assertExitCode(0);
});

it('says it is outside the send window and exits zero before the send time', function () {
    // Given a product launching tomorrow and the clock before the send time
    $this->travelTo(new DateTimeImmutable('2026-10-20 06:59:00 UTC'));
    Product::factory()->scheduled('2026-10-21')->create(['maker_id' => User::factory()->create()->id]);

    // When the command runs
    // Then it does nothing and says so
    $this->artisan('launchpad:queue-launch-reminders')->expectsOutput('outside the send window')->assertExitCode(0);
    expect(DB::table('notifications')->count())->toBe(0);
});

it('exits non-zero when a product failed, and still reminds the others', function () {
    // Given two products and a recorder that fails for one of them
    $this->travelTo(new DateTimeImmutable('2026-10-20 09:00:00 UTC'));
    $products = collect([1, 2])->map(fn () => Product::factory()->scheduled('2026-10-21')->create(['maker_id' => User::factory()->create()->id]))->sortBy('id')->values();
    $bad = $products[0]->id;
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

    // When the command runs
    // Then the failure is counted, reported, the other product is reminded and the exit code is 1
    $this->artisan('launchpad:queue-launch-reminders')->expectsOutput('found 2, created 1, failed 1')->assertExitCode(1);
    Exceptions::assertReported(RuntimeException::class);
    expect(DB::table('notifications')->count())->toBe(1);
});

it('is scheduled once, every ten minutes, with a 15 minute overlap lock and on one server', function () {
    // Given the application's schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:queue-launch-reminders'));

    // When the entries for the command are read
    // Then there is exactly one, protected like the others
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('*/10 * * * *')->and($event->withoutOverlapping)->toBeTrue()->and($event->expiresAt)->toBe(15)->and($event->onOneServer)->toBeTrue()->and($event->runInBackground)->toBeTrue();
});
