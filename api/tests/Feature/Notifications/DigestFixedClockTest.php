<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/DigestFixtures.php';

// No travelTo here on purpose: the instant is passed in, and the action must use it for its first checks.
it('uses the instant it is given for the window, the period and the run, not the wall clock', function () {
    // Given a fixed instant at 07:00 UTC two days ago, a frozen "yesterday" and members
    $now = CarbonImmutable::now('UTC')->startOfDay()->subDays(2)->addHours(7); // two days before the wall clock: only the given instant makes it fresh
    $yesterday = $now->subDay()->toDateString();
    digestUsers(3);
    digestFreeze($yesterday, [digestProduct($yesterday)]);

    // When the digest runs at that instant
    $before = $now->subSecond();
    $notDue = digestAction()->handle($before)['daily_top5'];
    $result = digestAction()->handle($now)['daily_top5'];

    // Then a second earlier is not due, and the run uses the instant's period and start time
    expect($notDue)->toBeNull();
    expect($result->period)->toBe($yesterday)->and($result->state)->toBe('completed')->and($result->enqueued)->toBe(DB::table('users')->count());
    expect(CarbonImmutable::parse((string) digestRun('daily_top5', $yesterday)['started_at'])->getTimestamp())->toBe($now->getTimestamp());
});
