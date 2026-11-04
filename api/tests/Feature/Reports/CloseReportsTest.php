<?php

use App\Actions\Report\CloseReports;
use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00.654321 UTC')));

it('closes only the open reports of that exact target and leaves the rest untouched', function () {
    // Given open reports on a comment, on another comment, on a product whose id equals nothing, and a report already closed
    $comment = Comment::factory()->create();
    $other = Comment::factory()->create();
    $product = Product::factory()->live()->create();
    $staff = User::factory()->role(Role::Reviewer)->create();
    $targets = Report::factory()->forComment($comment)->count(2)->create();
    $elsewhere = Report::factory()->forComment($other)->create();
    $onProduct = Report::factory()->forProduct($product)->create();
    $alreadyClosed = Report::factory()->forComment($comment)->dismissed($staff)->create();
    $before = DB::table('reports')->whereIn('id', [$elsewhere->id, $onProduct->id, $alreadyClosed->id])->get()->keyBy('id');

    // When the comment's reports are closed as resolved by the reviewer with a note
    $closed = app(CloseReports::class)->forTarget('comment', $comment->id, ReportStatus::Resolved, $staff, 'Removed as spam');

    // Then exactly the two open ones changed
    expect($closed)->toBe(2);
    foreach ($targets as $report) {
        $row = Report::query()->findOrFail($report->id);
        expect($row->status)->toBe(ReportStatus::Resolved)->and($row->handled_by)->toBe($staff->id)->and($row->resolution_note)->toBe('Removed as spam')
            ->and($row->handled_at)->not->toBeNull()->and($row->handled_at->micro)->toBe(654321);
    }
    $after = DB::table('reports')->whereIn('id', [$elsewhere->id, $onProduct->id, $alreadyClosed->id])->get()->keyBy('id');
    expect($after->toArray())->toEqual($before->toArray());
});

it('closes as the system with no handler, and returns zero when nothing is open', function () {
    // Given an open report and a target with none
    $comment = Comment::factory()->create();
    $report = Report::factory()->forComment($comment)->create();

    // When the system closes them, then closes again
    $first = app(CloseReports::class)->forTarget('comment', $comment->id, ReportStatus::Resolved, null, 'deleted_by_author');
    $again = app(CloseReports::class)->forTarget('comment', $comment->id, ReportStatus::Resolved, null, 'deleted_by_author');
    $none = app(CloseReports::class)->forTarget('comment', Comment::factory()->create()->id, ReportStatus::Dismissed, null, null);

    // Then the first closed one row with no handler, the others none
    expect([$first, $again, $none])->toBe([1, 0, 0]);
    $row = $report->fresh();
    expect($row->status)->toBe(ReportStatus::Resolved)->and($row->handled_by)->toBeNull()->and($row->resolution_note)->toBe('deleted_by_author');
});

it('can dismiss', function () {
    // Given an open report and a reviewer
    $comment = Comment::factory()->create();
    $report = Report::factory()->forComment($comment)->create();
    $staff = User::factory()->role(Role::Reviewer)->create();

    // When it is dismissed without a note
    app(CloseReports::class)->forTarget('comment', $comment->id, ReportStatus::Dismissed, $staff, null);

    // Then the status, handler and instant are set and the note stays empty
    $row = $report->fresh();
    expect($row->status)->toBe(ReportStatus::Dismissed)->and($row->handled_by)->toBe($staff->id)->and($row->resolution_note)->toBeNull();
});

it('locks the open reports FOR UPDATE ordered by id', function () {
    // Given three open reports on a comment
    $comment = Comment::factory()->create();
    $ids = Report::factory()->forComment($comment)->count(3)->create()->pluck('id')->sort()->values()->all();
    $sql = null;
    DB::listen(function ($query) use (&$sql) {
        if (str_contains(strtolower($query->sql), 'for update')) {
            $sql = strtolower($query->sql);
        }
    });

    // When they are locked inside a transaction
    $locked = DB::transaction(fn () => app(CloseReports::class)->lockOpen('comment', $comment->id));

    // Then the select is ordered by id, locks for update, and returns the ids in order
    expect($sql)->toContain('order by "id" asc')->toContain('for update')->and($locked->all())->toBe($ids);
});

it('only closes rows that are still open (a report closed between the lock and the update stays as it was)', function () {
    // Given a locked id whose row was closed by somebody else meanwhile
    $comment = Comment::factory()->create();
    $staff = User::factory()->role(Role::Reviewer)->create();
    $report = Report::factory()->forComment($comment)->create();
    $ids = collect([$report->id]);
    DB::table('reports')->where('id', $report->id)->update(['status' => 'dismissed', 'handled_by' => $staff->id, 'handled_at' => now()]);

    // When the stale id list is closed as resolved
    $closed = app(CloseReports::class)->close($ids, ReportStatus::Resolved, null, 'late');

    // Then nothing is overwritten
    expect($closed)->toBe(0)->and($report->fresh()->status)->toBe(ReportStatus::Dismissed);
});
