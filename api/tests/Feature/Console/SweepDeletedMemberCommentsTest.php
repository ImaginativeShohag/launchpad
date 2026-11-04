<?php

use App\Jobs\DeleteMemberComments;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

it('dispatches the clean-up for each deleted member that still has comments that were not erased', function () {
    // Given a deleted member with a comment, one whose comments are all erased, a deleted member with none, and a live member with a comment
    Queue::fake();
    $stuck = User::factory()->create(['deleted_at' => now()]);
    Comment::factory()->count(2)->create(['user_id' => $stuck->id]);
    $erased = User::factory()->create(['deleted_at' => now()]);
    Comment::factory()->deletedByAuthor()->create(['user_id' => $erased->id]);
    User::factory()->create(['deleted_at' => now()]);
    Comment::factory()->create(['user_id' => User::factory()->create()->id]);
    // A staff-removed comment of a deleted member that still has its body counts as work to do
    $removed = User::factory()->create(['deleted_at' => now()]);
    Comment::factory()->removed()->create(['user_id' => $removed->id]);

    // When the sweep runs
    $this->artisan('launchpad:sweep-deleted-member-comments')->expectsOutput('Dispatched 2 clean-up job(s).')->assertSuccessful();

    // Then one job per member with work is queued, once each, and nobody else
    Queue::assertPushed(DeleteMemberComments::class, 2);
    Queue::assertPushed(DeleteMemberComments::class, fn (DeleteMemberComments $job) => $job->userId === $stuck->id);
    Queue::assertPushed(DeleteMemberComments::class, fn (DeleteMemberComments $job) => $job->userId === $removed->id);
});

it('does nothing when no deleted member has comments left', function () {
    // Given only live members with comments
    Queue::fake();
    Comment::factory()->create();

    // When the sweep runs
    $this->artisan('launchpad:sweep-deleted-member-comments')->expectsOutput('Dispatched 0 clean-up job(s).')->assertSuccessful();

    // Then nothing is queued
    Queue::assertNothingPushed();
});

it('exits non-zero when a dispatch fails and still tries the rest', function () {
    // Given two stuck deleted members and a queue that throws on the first push only
    Exceptions::fake();
    foreach (range(1, 2) as $i) {
        Comment::factory()->create(['user_id' => User::factory()->create(['deleted_at' => now()])->id]);
    }
    $pushes = 0;
    $flaky = Mockery::mock(Illuminate\Contracts\Queue\Queue::class);
    $flaky->shouldReceive('push')->andReturnUsing(function () use (&$pushes) {
        if (++$pushes === 1) {
            throw new RuntimeException('queue down');
        }

        return 'id';
    });
    Queue::shouldReceive('connection')->andReturn($flaky);

    // When the sweep runs
    $this->artisan('launchpad:sweep-deleted-member-comments')->expectsOutput('Dispatched 2 clean-up job(s).')->assertFailed();

    // Then both pushes were attempted and the failure was reported
    expect($pushes)->toBe(2);
    Exceptions::assertReportedCount(1);
});

it('also dispatches for a deleted reporter whose open reports can be closed, but not while another eligible report keeps them open', function () {
    // Given deleted reporters: one with a closable open report, one whose target has an eligible sibling report, one with none
    Queue::fake();
    $closable = User::factory()->create(['deleted_at' => now()]);
    $blocked = User::factory()->create(['deleted_at' => now()]);
    User::factory()->create(['deleted_at' => now()]);
    Report::factory()->forComment(Comment::factory()->create())->create(['reporter_id' => $closable->id]);
    $guarded = Comment::factory()->create();
    Report::factory()->forComment($guarded)->create(['reporter_id' => $blocked->id]);
    Report::factory()->forComment($guarded)->create();

    // When the sweep runs
    $this->artisan('launchpad:sweep-deleted-member-comments')->expectsOutput('Dispatched 1 clean-up job(s).')->assertSuccessful();

    // Then only the closable reporter's job is queued
    Queue::assertPushed(DeleteMemberComments::class, 1);
    Queue::assertPushed(DeleteMemberComments::class, fn (DeleteMemberComments $job) => $job->userId === $closable->id);
});

it('repairs a lost dispatch: after the sweep (real sync queue) the report is closed', function () {
    // Given a deleted reporter whose job was lost
    $gone = User::factory()->create(['deleted_at' => now()]);
    $report = Report::factory()->forComment(Comment::factory()->create())->create(['reporter_id' => $gone->id]);

    // When the sweep runs, and again
    $this->artisan('launchpad:sweep-deleted-member-comments')->assertSuccessful();
    $this->artisan('launchpad:sweep-deleted-member-comments')->expectsOutput('Dispatched 0 clean-up job(s).')->assertSuccessful();

    // Then the report is dismissed by the system, and a second sweep found nothing to do
    expect($report->fresh()->status->value)->toBe('dismissed')->and($report->fresh()->resolution_note)->toBe('reporter_deleted');
});
