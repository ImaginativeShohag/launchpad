<?php

use App\Actions\Notification\NotificationDraft;
use App\Actions\Notification\Reconcile;
use App\Actions\Notification\RecordNotification;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Events\CommentCreated;
use App\Events\ProductApproved;
use App\Events\ProductWentLive;
use App\Listeners\NotifyNewComment;
use App\Listeners\NotifyProductLive;
use App\Listeners\NotifyReviewOutcome;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/CommentFixtures.php';
require_once __DIR__.'/../../Support/MailFixtures.php';

/** A review row of $product with an id and a created_at at $at (the table is append-only: inserted raw). */
function reconcileReview(Product $product, CarbonImmutable $at, string $decision = 'approved'): string
{
    $id = strtolower((string) Str::ulid($at));
    DB::table('product_reviews')->insert([
        'id' => $id, 'product_id' => $product->id, 'reviewer_id' => User::factory()->role(Role::Reviewer)->create()->id,
        'decision' => $decision, 'note' => $decision === 'approved' ? null : 'needs work', 'created_at' => $at->format('Y-m-d H:i:s.uP'),
    ]);

    return $id;
}

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC');
    $this->travelTo($this->now);
});

it('creates nothing on a normal run after the listeners ran', function () {
    // Given a review, a launch and a comment that the listeners already announced
    $product = Product::factory()->live()->create(['launched_at' => $this->now->subHours(2)]);
    $review = reconcileReview($product, $this->now->subHour());
    app(NotifyReviewOutcome::class)->notify(new ProductApproved($product->id, null, $review));
    app(NotifyProductLive::class)->notify(new ProductWentLive($product->id));
    $comment = Comment::factory()->create(['product_id' => $product->id, 'created_at' => $this->now->subMinutes(30), 'updated_at' => $this->now->subMinutes(30), 'id' => strtolower((string) Str::ulid($this->now->subMinutes(30)))]);
    app(NotifyNewComment::class)->notify(new CommentCreated($comment->id, $product->id, $comment->user_id));
    $before = producerCounts();

    // When the sweep runs
    $result = app(Reconcile::class)->handle($this->now);

    // Then it found all three, created none and wrote nothing
    expect($result)->toBe(['found' => 3, 'created' => 0, 'failed' => 0]);
    expect(producerCounts())->toBe($before);
});

it('recreates a lost notification and repairs a lost outbox row, once', function () {
    // Given an announced launch whose bell row was lost, and an announced review whose outbox row was lost
    $liveProduct = Product::factory()->live()->create(['launched_at' => $this->now->subHour()]);
    app(NotifyProductLive::class)->notify(new ProductWentLive($liveProduct->id));
    $reviewed = Product::factory()->live()->create(['launched_at' => $this->now->subDays(5)]);
    $review = reconcileReview($reviewed, $this->now->subHour());
    app(NotifyReviewOutcome::class)->notify(new ProductApproved($reviewed->id, null, $review));
    DB::table('notifications')->where('dedupe_key', 'like', 'product_live:%')->delete();
    DB::table('email_messages')->where('type', 'review_outcome')->delete();
    $mailBefore = DB::table('email_messages')->where('type', 'product_live')->count();

    // When the sweep runs
    $first = app(Reconcile::class)->handle($this->now);
    $second = app(Reconcile::class)->handle($this->now);

    // Then the lost bell row is back (counted created), the lost mail is back (a repair), and the second run changes nothing
    expect($first['created'])->toBe(1)->and($first['failed'])->toBe(0)->and($second['created'])->toBe(0);
    expect(DB::table('notifications')->where('dedupe_key', 'like', 'product_live:%')->count())->toBe(1);
    expect(DB::table('email_messages')->where('type', 'review_outcome')->count())->toBe(1)->and(DB::table('email_messages')->where('type', 'product_live')->count())->toBe($mailBefore);
});

it('leaves reviews and launches older than 48 hours alone', function (int $hours, int $expectedFound) {
    // Given a review and a launch $hours hours old that were never announced
    $product = Product::factory()->live()->create(['launched_at' => $this->now->subHours($hours)]);
    reconcileReview($product, $this->now->subHours($hours));

    // When the sweep runs
    $result = app(Reconcile::class)->handle($this->now);

    // Then both are picked up at 47 hours and neither at 49
    expect($result['found'])->toBe($expectedFound)->and($result['created'])->toBe($expectedFound);
    expect(DB::table('notifications')->count())->toBe($expectedFound);
})->with([[47, 2], [49, 0]]);

it('creates no second bell row or mail for a restored product with the same launch date', function () {
    // Given a live product announced once, taken down and restored on the same launch day
    $product = Product::factory()->live()->create(['launched_at' => $this->now->subHours(3)]);
    app(NotifyProductLive::class)->notify(new ProductWentLive($product->id));
    DB::table('products')->where('id', $product->id)->update(['status' => ProductStatus::TakenDown->value]);
    DB::table('products')->where('id', $product->id)->update(['status' => ProductStatus::Live->value, 'launched_at' => $this->now->subHour()]);

    // When the sweep runs
    $result = app(Reconcile::class)->handle($this->now);

    // Then nothing new is created
    expect($result['created'])->toBe(0)->and(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 1]);
});

it('derives the comment mail key from the comment, so a later sweep makes the same key', function () {
    // Given a comment announced by its listener at the hour it was written
    $product = Product::factory()->live()->create(['launched_at' => $this->now->subDays(10)]);
    $at = $this->now->subHours(3);
    $comment = Comment::factory()->create(['product_id' => $product->id, 'id' => strtolower((string) Str::ulid($at)), 'created_at' => $at, 'updated_at' => $at]);
    app(NotifyNewComment::class)->notify(new CommentCreated($comment->id, $product->id, $comment->user_id));
    $key = DB::table('email_messages')->value('idempotency_key');
    DB::table('notifications')->delete();

    // When the sweep runs three hours later and recreates the bell row
    $result = app(Reconcile::class)->handle($this->now);

    // Then the mail key is the same (the comment's hour, not now) and no second mail exists
    expect($result['created'])->toBe(1)->and(DB::table('email_messages')->count())->toBe(1)->and(DB::table('email_messages')->value('idempotency_key'))->toBe($key);
    expect($key)->toContain($at->format('YmdH'));
});

it('ignores removed and deleted comments', function () {
    // Given a removed and a deleted comment inside the window
    $product = Product::factory()->live()->create(['launched_at' => $this->now->subDays(10)]);
    $at = $this->now->subHour();
    Comment::factory()->removed()->create(['product_id' => $product->id, 'id' => strtolower((string) Str::ulid($at)), 'created_at' => $at, 'updated_at' => $at]);
    Comment::factory()->create(['product_id' => $product->id, 'id' => strtolower((string) Str::ulid($at->addSecond())), 'created_at' => $at, 'updated_at' => $at, 'deleted_at' => $at]);

    // When the sweep runs
    // Then nothing is found
    expect(app(Reconcile::class)->handle($this->now))->toBe(['found' => 0, 'created' => 0, 'failed' => 0]);
});

it('counts a throwing item as failed, reports it, keeps going, and the command exits 1', function () {
    // Given three unannounced launches and a recorder that throws for one of them
    $products = Product::factory()->live()->count(3)->create(['launched_at' => $this->now->subHour()]);
    $bad = $products->sortBy('id')->values()[1]->id;
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
    $exit = Artisan::call('launchpad:reconcile-notifications');

    // Then the other two were created, one failure was reported and counted, and the exit code is 1
    expect($exit)->toBe(1)->and(Artisan::output())->toContain('found 3, created 2, failed 1');
    expect(DB::table('notifications')->count())->toBe(2);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'boom');
});

it('prints the counts and exits zero when all is well', function () {
    // Given nothing to reconcile
    // When the command runs
    $this->artisan('launchpad:reconcile-notifications')->expectsOutput('found 0, created 0, failed 0')->assertExitCode(0);
});

it('pages by primary key, 200 ids per query, with a flat number of statements', function () {
    // Given 450 reviews of one product inside the window (raw rows)
    $product = Product::factory()->live()->create(['launched_at' => $this->now->subDays(10)]);
    $reviewer = User::factory()->role(Role::Reviewer)->create();
    $rows = [];
    for ($i = 0; $i < 450; $i++) {
        $at = $this->now->subMinutes(30)->addMilliseconds($i);
        $rows[] = ['id' => strtolower((string) Str::ulid($at)), 'product_id' => $product->id, 'reviewer_id' => $reviewer->id, 'decision' => 'approved', 'note' => null, 'created_at' => $at->format('Y-m-d H:i:s.uP')];
    }
    DB::table('product_reviews')->insert($rows);
    $pages = 0;
    DB::listen(function ($query) use (&$pages) {
        if (str_starts_with(strtolower($query->sql), 'select "id" from "product_reviews"')) {
            $pages++;
        }
    });

    // When the sweep runs
    $result = app(Reconcile::class)->handle($this->now);

    // Then three id pages (200, 200, 50) were read and every review got a bell row
    expect($pages)->toBe(3)->and($result['found'])->toBe(450)->and($result['created'])->toBe(450);
});

it('is scheduled once at :45 with a 30 minute lock on one server', function () {
    // Given the schedule
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'launchpad:reconcile-notifications'));

    // When the entries are read
    // Then there is one, with the documented protection
    expect($events)->toHaveCount(1);
    $event = $events->first();
    expect($event->expression)->toBe('45 * * * *')->and($event->expiresAt)->toBe(30)->and($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue();
});
