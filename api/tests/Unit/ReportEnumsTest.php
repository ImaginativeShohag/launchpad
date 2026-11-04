<?php

use App\Enums\ReportReason;
use App\Enums\ReportStatus;

it('lists the report reasons the API accepts', function () {
    // Given the enum
    // When its values are read
    // Then they are the five documented reasons, in order
    expect(ReportReason::values())->toBe(['spam', 'abuse', 'misleading', 'inappropriate', 'other']);
});

it('lists the report statuses', function () {
    // Given the enum
    // When its values are read
    // Then they are the three documented statuses
    expect(ReportStatus::values())->toBe(['open', 'resolved', 'dismissed']);
});
