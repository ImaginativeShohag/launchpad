<?php

use App\Events\CommentCreated;
use App\Events\ProductApproved;
use App\Events\ProductChangesRequested;
use App\Events\ProductRejected;
use App\Events\ProductWentLive;
use App\Listeners\NotifyNewComment;
use App\Listeners\NotifyProductLive;
use App\Listeners\NotifyReviewOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;

it('reaches each producer exactly once per event', function (string $listener, Closure $event) {
    // Given a faked queue (the listeners are queued)
    Queue::fake();

    // When the event is dispatched
    event($event());

    // Then one job for that listener (and only that one) was pushed: one registration, no second one from discovery
    Queue::assertPushed(CallQueuedListener::class, 1);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === $listener && $job->method === 'notify');
})->with([
    'approved' => [NotifyReviewOutcome::class, fn () => new ProductApproved('p', null, 'r')],
    'rejected' => [NotifyReviewOutcome::class, fn () => new ProductRejected('p', null, 'r')],
    'changes requested' => [NotifyReviewOutcome::class, fn () => new ProductChangesRequested('p', null, 'r')],
    'went live' => [NotifyProductLive::class, fn () => new ProductWentLive('p')],
    'comment created' => [NotifyNewComment::class, fn () => new CommentCreated('c', 'p', 'u')],
]);

it('keeps the listener method off `handle` and the options of a durable queued job', function (string $listener) {
    // Given a listener class
    $reflection = new ReflectionClass($listener);
    $instance = new $listener;

    // When its shape is read
    // Then it is queued, not discoverable as `handle`, with five tries, the documented backoff and a timeout under retry_after
    expect($reflection->hasMethod('handle'))->toBeFalse()->and($reflection->hasMethod('notify'))->toBeTrue();
    expect($instance)->toBeInstanceOf(ShouldQueue::class);
    expect($instance->tries)->toBe(5)->and($instance->backoff())->toBe([10, 60, 300, 900]);
    expect($instance->timeout)->toBeLessThan(90)->toBeGreaterThan(0);
    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan($instance->timeout);
})->with([NotifyReviewOutcome::class, NotifyProductLive::class, NotifyNewComment::class]);
