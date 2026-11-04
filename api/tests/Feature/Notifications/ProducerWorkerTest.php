<?php

use App\Actions\Notification\NotificationDraft;
use App\Actions\Notification\RecordNotification;
use App\Enums\Role;
use App\Events\ProductApproved;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/MailFixtures.php';

/** A recorder that throws the first $failures times it is asked, then behaves. */
function workerFlakyRecorder(int $failures): void
{
    $state = new ArrayObject(['calls' => 0]);
    app()->instance('worker.state', $state);
    app()->bind(RecordNotification::class, fn () => new class($state, $failures) extends RecordNotification
    {
        public function __construct(private ArrayObject $state, private int $failures) {}

        public function handle(NotificationDraft $draft): bool
        {
            $this->state['calls']++;
            if ($this->state['calls'] <= $this->failures) {
                throw new RuntimeException('record failed');
            }

            return parent::handle($draft);
        }
    });
}

/** The listener jobs waiting in the database queue (the unread-count broadcast jobs are not counted). */
function workerListenerJobs(): int
{
    return DB::table('jobs')->where('payload', 'like', '%NotifyReviewOutcome%')->count();
}

function workerRunOnce(): ?int
{
    DB::table('jobs')->where('payload', 'like', '%NotifyReviewOutcome%')->update(['available_at' => time()]);
    Artisan::call('queue:work', ['--once' => true, '--tries' => 0, '--sleep' => 0, '--queue' => 'default']);
    $job = DB::table('jobs')->where('payload', 'like', '%NotifyReviewOutcome%')->first();

    return $job === null ? null : $job->available_at - time();
}

/** An approved product with its review row, and the real database queue. */
function workerApproval(): array
{
    config(['queue.default' => 'database']);
    $maker = User::factory()->create();
    $product = Product::factory()->pending()->create(['maker_id' => $maker->id]);
    $reviewId = strtolower((string) Str::ulid());
    DB::table('product_reviews')->insert(['id' => $reviewId, 'product_id' => $product->id, 'reviewer_id' => User::factory()->role(Role::Reviewer)->create()->id, 'decision' => 'approved', 'note' => null, 'created_at' => now()]);

    return [$maker, $product, $reviewId];
}

it('queues the listener on the real database queue instead of running it inline', function () {
    // Given the database queue and an approved review
    [, $product, $reviewId] = workerApproval();

    // When the event is dispatched
    ProductApproved::dispatch($product->id, null, $reviewId);

    // Then one listener job is waiting and nothing was written yet
    expect(workerListenerJobs())->toBe(1)->and(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
});

it('retries a failing listener until it succeeds, writing exactly once and leaving failed_jobs empty', function () {
    // Given the queued job and a recorder that fails twice
    [, $product, $reviewId] = workerApproval();
    workerFlakyRecorder(2);
    ProductApproved::dispatch($product->id, null, $reviewId);

    // When the worker runs three times
    $delays = [workerRunOnce(), workerRunOnce(), workerRunOnce()];

    // Then the first two failures were released with 10 and 60 seconds, the third succeeded, and one notification and one mail exist
    expect($delays[0])->toBeGreaterThanOrEqual(7)->toBeLessThanOrEqual(10);
    expect($delays[1])->toBeGreaterThanOrEqual(57)->toBeLessThanOrEqual(60);
    expect($delays[2])->toBeNull()->and(workerListenerJobs())->toBe(0);
    expect(producerCounts())->toBe(['notifications' => 1, 'email_messages' => 1])->and(DB::table('failed_jobs')->count())->toBe(0);
    expect(app('worker.state')['calls'])->toBe(3);
});

it('fails the job for good after five tries with the documented delays, and writes nothing', function () {
    // Given a recorder that always fails
    [, $product, $reviewId] = workerApproval();
    workerFlakyRecorder(1000);
    ProductApproved::dispatch($product->id, null, $reviewId);

    // When the worker runs five times
    $delays = [];
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $delays[] = workerRunOnce();
    }

    // Then the retries waited 10, 60, 300 and 900 seconds and the fifth failure moved the job to failed_jobs
    foreach ([10, 60, 300, 900] as $i => $expected) {
        expect($delays[$i])->toBeGreaterThanOrEqual($expected - 3)->toBeLessThanOrEqual($expected);
    }
    expect($delays[4])->toBeNull()->and(workerListenerJobs())->toBe(0);
    expect(DB::table('failed_jobs')->where('payload', 'like', '%NotifyReviewOutcome%')->count())->toBe(1)->and(producerCounts())->toBe(['notifications' => 0, 'email_messages' => 0]);
    DB::table('failed_jobs')->delete();
});
