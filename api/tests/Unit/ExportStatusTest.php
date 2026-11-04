<?php

use App\Enums\ExportStatus;

it('lists the five statuses of the export_status enum in order', function () {
    // Given the enum
    // When its values are read
    // Then they are the documented ones
    expect(ExportStatus::values())->toBe(['queued', 'processing', 'ready', 'failed', 'expired']);
});

it('tells active, in-flight and final statuses apart', function (ExportStatus $status, bool $active, bool $inFlight) {
    // Given a status
    // When it is asked
    // Then active covers queued, processing and ready, in flight only the first two
    expect($status->isActive())->toBe($active)->and($status->isInFlight())->toBe($inFlight);
})->with([
    'queued' => [ExportStatus::Queued, true, true],
    'processing' => [ExportStatus::Processing, true, true],
    'ready' => [ExportStatus::Ready, true, false],
    'failed' => [ExportStatus::Failed, false, false],
    'expired' => [ExportStatus::Expired, false, false],
]);
