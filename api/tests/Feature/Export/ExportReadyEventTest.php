<?php

use App\Enums\ExportStatus;
use App\Events\ExportReady;
use App\Models\Export;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('exports');
    $this->travelTo(dashNow());
    $this->raised = new ArrayObject;
    // A real listener: no Event::fake, so the delivery path is the production one.
    Event::listen(ExportReady::class, function (ExportReady $event) {
        $this->raised[] = $event;
    });
});

afterEach(function () {
    Export::flushEventListeners();
});

it('raises ExportReady exactly once with the three ids when the export finishes', function () {
    // Given a queued export
    $export = exportFor(dashProduct(), 'queued');

    // When it is generated
    exportGenerate($export);

    // Then one event reached the real listener, with the export, product and owner ids
    expect($this->raised)->toHaveCount(1);
    $event = $this->raised[0];
    expect([$event->exportId, $event->productId, $event->userId])->toBe([$export->id, $export->product_id, $export->user_id]);
});

it('raises nothing when the finishing update is rolled back', function () {
    // Given a finishing update that fails
    $export = exportFor(dashProduct(), 'queued');
    Export::updating(function (Export $e) {
        if ($e->status === ExportStatus::Ready) {
            throw new RuntimeException('finish fails');
        }
    });

    // When the job runs
    $run = fn () => exportGenerate($export);

    // Then it throws, the row did not become ready and no event was raised
    expect($run)->toThrow(RuntimeException::class);
    expect($export->refresh()->status)->toBe(ExportStatus::Processing)->and($this->raised)->toHaveCount(0);
});

it('raises nothing again for a ready export and never for failed, expired or too_large ones', function () {
    // Given one finished export, and exports that end failed, expired and too large
    $done = exportFor(dashProduct(), 'queued');
    exportGenerate($done);
    $raisedOnce = count($this->raised);
    $failed = exportFor(dashProduct(), 'failed');
    $expired = exportFor(dashProduct(), 'expired');
    $large = exportFor(dashProduct(), 'queued');
    exportBindBuilder(function (Closure $real) {
        $built = $real();
        $built['hourly'] = random_bytes(6 * 1024 * 1024);

        return $built;
    });

    // When they are all run (the finished one a second time)
    exportGenerate($done);
    exportGenerate($failed);
    exportGenerate($expired);
    exportGenerate($large);

    // Then no event was raised beyond the first one
    expect($raisedOnce)->toBe(1)->and($this->raised)->toHaveCount(1);
});

it('does not fail the job or flip the row when a listener throws', function () {
    // Given a listener that throws
    Exceptions::fake();
    Event::listen(ExportReady::class, function () {
        throw new RuntimeException('listener down');
    });
    $export = exportFor(dashProduct(), 'queued');

    // When the job runs
    exportGenerate($export);

    // Then the export is ready, the failure was reported and the job returned normally
    expect($export->refresh()->status)->toBe(ExportStatus::Ready)->and($export->error)->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'listener down');
});

it('carries only the three ids: no file name, url or number', function () {
    // Given the event class
    $properties = array_map(fn (ReflectionProperty $p) => $p->getName(), (new ReflectionClass(ExportReady::class))->getProperties(ReflectionProperty::IS_PUBLIC));

    // When its public properties are listed
    // Then they are exactly the three ids
    expect($properties)->toBe(['exportId', 'productId', 'userId']);
    expect((new ReflectionClass(ExportReady::class))->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue();
});

it('raises the event only after the transaction of the finish committed', function () {
    // Given a listener that records the state of the row it sees
    $export = exportFor(dashProduct(), 'queued');
    $seen = [];
    Event::listen(ExportReady::class, function (ExportReady $event) use (&$seen) {
        $seen[] = DB::table('exports')->where('id', $event->exportId)->value('status');
    });

    // When the job runs
    exportGenerate($export);

    // Then the committed row was already ready when the listener ran
    expect($seen)->toBe(['ready']);
});
