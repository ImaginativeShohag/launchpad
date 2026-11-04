<?php

use App\Actions\Export\ExportFiles;
use App\Enums\ExportStatus;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Events\ExportReady;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('exports');
    $this->travelTo(dashNow());
    $this->raised = new ArrayObject;
    Event::listen(ExportReady::class, function (ExportReady $event) {
        $this->raised[] = [$event->exportId, $event->productId, $event->userId];
    });
});

afterEach(function () {
    Export::flushEventListeners();
});

function generateQueued(): Export
{
    return exportFor(dashProduct(), 'queued');
}

it('moves queued to processing to ready with the file columns and the retention window', function () {
    // Given a queued export, the retention window set to 3 days, and a builder that records the status it sees
    $export = generateQueued();
    app(Settings::class)->set(SettingKey::RetentionExportsDays, 3, User::factory()->role(Role::Admin)->create());
    $seen = [];
    exportBindBuilder(function (Closure $real, Export $e) use (&$seen) {
        $seen[] = DB::table('exports')->where('id', $e->id)->value('status');

        return $real();
    });

    // When the job runs
    exportGenerate($export);

    // Then it was processing during the build and is ready after, with size, counts and a 3 day expiry
    $export->refresh();
    expect($seen)->toBe(['processing'])->and($export->status)->toBe(ExportStatus::Ready)->and($export->file_size)->toBeGreaterThan(0)
        ->and($export->row_counts)->toHaveKeys(['summary', 'hourly', 'referrers_daily', 'rank_timeline'])
        ->and($export->completed_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($export->expires_at->toIso8601String())->toBe(now()->addDays(3)->toIso8601String())->and($export->error)->toBeNull();
    Storage::disk('exports')->assertExists($export->file_path);
});

it('does nothing and raises nothing when run again on a ready export', function () {
    // Given an export that finished once
    $export = generateQueued();
    exportGenerate($export);
    $first = $export->refresh()->only(['file_path', 'file_size', 'completed_at', 'expires_at']);
    exportBindBuilder(fn () => throw new RuntimeException('must not build'));

    // When the job runs again (a lost ack)
    exportGenerate($export);

    // Then nothing was rebuilt or rewritten and the event was raised once
    expect($export->refresh()->only(['file_path', 'file_size', 'completed_at', 'expires_at']))->toEqual($first);
    expect($this->raised)->toHaveCount(1);
});

it('ignores a missing row and rows that are failed or expired', function (string $state) {
    // Given an export that is final, and one that does not exist
    $export = exportFor(dashProduct(), $state);
    exportBindBuilder(fn () => throw new RuntimeException('must not build'));

    // When the job runs for both
    exportGenerate($export);
    exportGenerate(exportUnknownId());

    // Then nothing happened
    expect($export->refresh()->status->value)->toBe($state)->and($this->raised)->toHaveCount(0);
    expect(Storage::disk('exports')->allFiles())->toBe([]);
})->with(['failed', 'expired']);

it('ends ready at the same path with one file and one event after a failed first attempt', function () {
    // Given a builder that fails once, as a retry would see
    $export = generateQueued();
    $calls = 0;
    exportBindBuilder(function (Closure $real) use (&$calls) {
        if (++$calls === 1) {
            throw new RuntimeException('first attempt dies');
        }

        return $real();
    });

    // When the first attempt throws and the retry runs
    expect(fn () => exportGenerate($export))->toThrow(RuntimeException::class);
    expect($export->refresh()->status)->toBe(ExportStatus::Processing);
    exportGenerate($export);

    // Then it is ready at the deterministic path, with one file and one event
    $export->refresh();
    expect($export->status)->toBe(ExportStatus::Ready)->and($export->file_path)->toBe($export->product_id.'/'.$export->id.'.zip');
    expect(Storage::disk('exports')->allFiles())->toBe([$export->file_path])->and($this->raised)->toHaveCount(1);
});

it('declares the metadata that keeps it safe: unique, queued, timeouts, tries and backoff', function () {
    // Given the job
    $job = new GenerateExport('01ARZ3NDEKTSV4RRFFQ69G5FAV');

    // When its properties are read
    // Then the unique lock outlives every try, the timeout is under retry_after, and the id is the export id
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)->toBeInstanceOf(ShouldQueue::class);
    expect($job->uniqueId())->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')->and($job->uniqueFor)->toBe(600)->and($job->timeout)->toBe(75)->and($job->tries)->toBe(3)->and($job->backoff())->toBe([10, 60]);
    expect($job->uniqueFor)->toBeGreaterThan($job->tries * $job->timeout + array_sum($job->backoff()))->and($job->tries * $job->timeout + array_sum($job->backoff()))->toBe(295);
    expect($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'))->and(config('queue.connections.database.retry_after'))->toBe(90);
});

it('drops a second dispatch while the unique lock is held', function () {
    // Given a queued export whose unique lock is held
    $export = generateQueued();
    $job = new GenerateExport($export->id);
    expect(app(UniqueLock::class)->acquire($job))->toBeTrue();

    // When the job is dispatched again on the sync queue
    GenerateExport::dispatch($export->id);

    // Then it did not run
    expect($export->refresh()->status)->toBe(ExportStatus::Queued);
});

it('runs through the queue and releases the lock afterwards', function () {
    // Given a queued export
    $export = generateQueued();

    // When it is dispatched on the sync queue
    GenerateExport::dispatch($export->id);

    // Then it finished, and the unique lock is free again
    expect($export->refresh()->status)->toBe(ExportStatus::Ready);
    expect(app(UniqueLock::class)->acquire(new GenerateExport($export->id)))->toBeTrue();
});

it('expires an export whose owner was deleted before it started', function () {
    // Given a queued export of a deleted member
    $export = generateQueued();
    DB::table('users')->where('id', $export->user_id)->update(['deleted_at' => now()]);

    // When the job runs
    exportGenerate($export);

    // Then it is expired with no file and no event
    expect($export->refresh()->status)->toBe(ExportStatus::Expired)->and($export->file_path)->toBeNull();
    expect(Storage::disk('exports')->allFiles())->toBe([])->and($this->raised)->toHaveCount(0);
});

it('expires an export and removes its file when the owner is deleted during the build', function () {
    // Given a builder that deletes the owner mid-build
    $export = generateQueued();
    exportBindBuilder(function (Closure $real, Export $e) {
        $built = $real();
        DB::table('users')->where('id', $e->user_id)->update(['deleted_at' => now()]);

        return $built;
    });

    // When the job runs
    exportGenerate($export);

    // Then the export is expired, the written file is gone and no event was raised
    expect($export->refresh()->status)->toBe(ExportStatus::Expired);
    expect(Storage::disk('exports')->allFiles())->toBe([])->and($this->raised)->toHaveCount(0);
});

it('removes the written file when the row was expired or failed during the build', function (string $final) {
    // Given a builder that lets the purge (or the sweeper) finish the row mid-build
    $export = generateQueued();
    exportBindBuilder(function (Closure $real, Export $e) use ($final) {
        $built = $real();
        DB::table('exports')->where('id', $e->id)->update(['status' => $final, 'error' => $final === 'failed' ? 'stalled' : null]);

        return $built;
    });

    // When the job runs
    exportGenerate($export);

    // Then the file it wrote is removed and no event is raised
    expect($export->refresh()->status->value)->toBe($final);
    expect(Storage::disk('exports')->allFiles())->toBe([])->and($this->raised)->toHaveCount(0);
})->with(['expired', 'failed']);

it('keeps the file of a row that another run already finished', function () {
    // Given a builder that lets a duplicate run finish the same export first
    $export = generateQueued();
    exportBindBuilder(function (Closure $real, Export $e) {
        $built = $real();
        DB::table('exports')->where('id', $e->id)->update([
            'status' => 'ready', 'file_path' => $e->product_id.'/'.$e->id.'.zip', 'file_size' => 5, 'row_counts' => '{}', 'completed_at' => now(), 'expires_at' => now()->addDay(),
        ]);

        return $built;
    });

    // When the job finishes second
    exportGenerate($export);

    // Then the shared file stays (it is the ready row's file) and no second event is raised
    Storage::disk('exports')->assertExists($export->product_id.'/'.$export->id.'.zip');
    expect($this->raised)->toHaveCount(0);
});

it('fails an export over 5 MB as too_large without a file, a temp file, an event or a retry', function () {
    // Given a builder that returns incompressible data over the cap
    $export = generateQueued();
    $before = exportTempFiles();
    exportBindBuilder(function (Closure $real) {
        $built = $real();
        $built['hourly'] = random_bytes(6 * 1024 * 1024);

        return $built;
    });

    // When the job runs
    exportGenerate($export);

    // Then it returned normally (no retry), the row is failed/too_large and nothing was stored or left behind
    expect($export->refresh()->status)->toBe(ExportStatus::Failed)->and($export->error)->toBe('too_large')->and($export->file_path)->toBeNull();
    expect(Storage::disk('exports')->allFiles())->toBe([])->and($this->raised)->toHaveCount(0)->and(exportTempFiles())->toBe($before);
});

it('leaves no temp file after a success or a failure', function () {
    // Given the temp files before
    $before = exportTempFiles();

    // When one export succeeds and one fails inside the build
    exportGenerate(generateQueued());
    $bad = generateQueued();
    exportBindBuilder(fn () => throw new RuntimeException('boom'));
    try {
        exportGenerate($bad);
    } catch (RuntimeException) {
    }

    // Then nothing is left
    expect(exportTempFiles())->toBe($before);
});

it('marks a row failed with a short code, removes the stored file, raises nothing and keeps the message out of the row', function () {
    // Given a processing export with a half-written file, and an exception whose message holds a path and a secret
    Exceptions::fake();
    $export = exportFor(dashProduct(), 'processing');
    $path = ExportFiles::path($export);
    Storage::disk('exports')->put($path, 'half');
    $error = new RuntimeException('/var/www/secret-token failed at '.$path);

    // When the queue reports the failure
    (new GenerateExport($export->id))->failed($error);

    // Then the row holds only the code, the file is gone, the failure was reported and no event was raised
    $export->refresh();
    expect($export->status)->toBe(ExportStatus::Failed)->and($export->error)->toBe('generation_failed');
    expect(json_encode($export->getAttributes()))->not->toContain('secret-token')->not->toContain('/var/www');
    Storage::disk('exports')->assertMissing($path);
    Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'secret-token'));
    expect($this->raised)->toHaveCount(0);
});

it('never shows the exception text to the maker', function () {
    // Given a failed export after an exception with a secret
    Exceptions::fake();
    $maker = signedInMember();
    $export = exportFor(dashProduct(DASH_DAY, ['maker_id' => $maker->id]), 'processing');
    (new GenerateExport($export->id))->failed(new RuntimeException('secret-token in /tmp/lpx123'));

    // When the maker reads it
    $body = $this->getJson("/api/v1/me/products/{$export->product_id}/exports/{$export->id}")->assertOk()->getContent();

    // Then only the code is there
    expect($body)->not->toContain('secret-token')->not->toContain('/tmp')->toContain('generation_failed');
});

it('leaves a ready export alone when failed() is called late', function () {
    // Given a ready export
    Exceptions::fake();
    $export = generateQueued();
    exportGenerate($export);
    $before = $export->refresh()->getAttributes();

    // When failed() is called (a throwing listener after the finish)
    (new GenerateExport($export->id))->failed(new RuntimeException('late'));

    // Then nothing changed and the file stays
    expect($export->refresh()->getAttributes())->toEqual($before);
    Storage::disk('exports')->assertExists($export->file_path);
});

it('takes no product or user lock and does the file work at the baseline transaction level', function () {
    // Given a queued export (the notification listener is detached: its recipient share lock is RecordNotification's, not the job's)
    // and a builder that records the transaction level
    Event::forget(ExportReady::class);
    $export = generateQueued();
    $levels = [];
    $baseline = DB::transactionLevel();
    exportBindBuilder(function (Closure $real) use (&$levels) {
        $levels[] = DB::transactionLevel();

        return $real();
    });

    // When the job runs
    $locks = exportLockingStatements(fn () => exportGenerate($export));

    // Then only export rows were locked, and the build ran outside any transaction of the job
    expect($locks)->not->toBeEmpty();
    foreach ($locks as $sql) {
        expect($sql)->toContain('from "exports"')->toContain('for update');
    }
    expect($levels)->toBe([$baseline]);
});

it('expires the export, without retries, when the product is deleted during the build', function () {
    // Given a builder whose product is soft-deleted before it reads it
    $export = generateQueued();
    $calls = 0;
    exportBindBuilder(function (Closure $real, Export $e) use (&$calls) {
        $calls++;
        Product::query()->whereKey($e->product_id)->firstOrFail()->delete();

        return $real();
    });

    // When the job runs
    exportGenerate($export);

    // Then the row is expired (not failed), nothing is stored, no event, and the job returned normally (no retry)
    expect($export->refresh()->status)->toBe(ExportStatus::Expired)->and($export->error)->toBeNull()->and($calls)->toBe(1);
    expect(Storage::disk('exports')->allFiles())->toBe([])->and($this->raised)->toHaveCount(0);
});

it('rejects early, by the text size, a build whose zip would be small but whose files are over 5 MB', function () {
    // Given a builder returning 6 MB of highly compressible text, and one returning 4 MB of it
    $big = generateQueued();
    exportBindBuilder(function (Closure $real) {
        $built = $real();
        $built['hourly'] = str_repeat('a', 6 * 1024 * 1024);

        return $built;
    });

    // When the job runs
    exportGenerate($big);

    // Then it is too_large though a zip of it would be tiny
    expect($big->refresh()->status)->toBe(ExportStatus::Failed)->and($big->error)->toBe('too_large');

    // And 4 MB of the same text is fine
    $ok = generateQueued();
    exportBindBuilder(function (Closure $real) {
        $built = $real();
        $built['hourly'] = str_repeat('a', 4 * 1024 * 1024);

        return $built;
    });
    exportGenerate($ok);
    expect($ok->refresh()->status)->toBe(ExportStatus::Ready);
});
