<?php

use App\Enums\SettingKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/DigestFixtures.php';
require_once __DIR__.'/../../Support/RetentionFixtures.php';

// Monday 2026-10-26: the week that just ended is Monday 19 to Sunday 25.
beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-26 07:00:00 UTC'));
    User::factory()->create();
});

function weeklyAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

it('is due only on Mondays from the weekly send time', function (string $now, bool $due) {
    // Given a launch in the week of the 19th
    $this->travelTo(new DateTimeImmutable($now.' UTC'));
    digestProduct('2026-10-22', ['votes_count' => 4]);

    // When the digest job runs at the given instant
    $result = digestAction()->handle(weeklyAt($now))['weekly_best'];

    // Then the weekly digest is due on Monday from 07:00 only
    expect($result === null)->toBe(! $due);
    expect(DB::table('digest_runs')->where('kind', 'weekly_best')->count())->toBe($due ? 1 : 0);
})->with([
    'Sunday night' => ['2026-10-25 23:59:59', false],
    'Monday before the send time' => ['2026-10-26 06:59:59', false],
    'Monday at the send time' => ['2026-10-26 07:00:00', true],
    'Monday evening' => ['2026-10-26 23:59:59', true],
    'Tuesday' => ['2026-10-27 07:00:00', false],
    'Saturday' => ['2026-10-31 12:00:00', false],
]);

it('covers the previous Monday to Sunday and ranks by the leaderboard order, leaving out Monday itself', function () {
    // Given launches across the week, two tied on votes and day, one on Sunday, one today, one taken down, one older
    $top = digestProduct('2026-10-19', ['votes_count' => 9]);
    $tieA = digestProduct('2026-10-22', ['votes_count' => 5]);
    $tieB = digestProduct('2026-10-22', ['votes_count' => 5]);
    $newer = digestProduct('2026-10-24', ['votes_count' => 5]);
    $sunday = digestProduct('2026-10-25', ['votes_count' => 1]);
    $monday = digestProduct('2026-10-26', ['votes_count' => 99]);
    $down = digestProduct('2026-10-23', ['votes_count' => 50]);
    digestTakeDown($down);
    $older = digestProduct('2026-10-18', ['votes_count' => 70]);

    // When the weekly digest is queued
    $result = digestAction()->handle(weeklyAt('2026-10-26 07:00:00'))['weekly_best'];

    // Then the period is the previous Monday and the list is votes, newest launch day, id; Monday's launch is not in it
    expect($result->period)->toBe('2026-10-19')->and($result->kind)->toBe('weekly_best');
    $expected = [$top->id, $newer->id, ...collect([$tieA, $tieB])->pluck('id')->sort()->values()->all(), $sunday->id];
    expect(json_decode(digestRun('weekly_best', '2026-10-19')['product_ids']))->toBe($expected);
    expect($monday->id)->not->toBeIn($expected)->and($older->id)->not->toBeIn($expected)->and($down->id)->not->toBeIn($expected);
});

it('writes the weekly payload and key, and keeps the daily digest independent', function () {
    // Given a member who opted out of the weekly best only, and a launch in the week
    $opted = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $opted->id, 'type' => 'weekly_best', 'enabled' => false, 'updated_at' => now()]);
    $product = digestProduct('2026-10-21', ['votes_count' => 3]);

    // When both digests are queued on Monday morning
    $results = digestAction()->handle(weeklyAt('2026-10-26 07:00:00'));

    // Then the weekly rows carry the week start, the opted-out member has none of them but does get the daily digest slot
    $weekly = collect(digestMail('weekly_best'));
    expect($weekly->pluck('user_id')->all())->not->toContain($opted->id);
    $row = $weekly->first();
    expect($row['idempotency_key'])->toBe('weekly_best:2026-10-19:'.$row['user_id'])->and(json_decode($row['payload'], true))->toBe(['week_start' => '2026-10-19', 'product_ids' => [$product->id]]);
    expect($results['daily_top5']->state)->toBe('waiting');
    expect(DB::table('notifications')->count())->toBe(0);
});

it('completes with nothing for an empty week', function () {
    // Given members and no launch in the week
    digestUsers(2);

    // When the weekly digest is queued
    $result = digestAction()->handle(weeklyAt('2026-10-26 07:00:00'))['weekly_best'];

    // Then the run is complete at once with nothing enqueued
    expect($result->state)->toBe('completed')->and($result->enqueued)->toBe(0)->and(DB::table('email_messages')->where('type', 'weekly_best')->count())->toBe(0);
    expect(digestRun('weekly_best', '2026-10-19')['completed_at'])->not->toBeNull();
});

it('reads the weekly send time live and falls back to 07:00 with a warning for a malformed value', function () {
    // Given a launch in the week and the weekly time moved to 09:15
    digestProduct('2026-10-21', ['votes_count' => 3]);
    retentionStoreRaw(SettingKey::DigestWeeklySendAtUtc, '09:15');

    // When it runs at 09:14 and 09:15
    $before = digestAction()->handle(weeklyAt('2026-10-26 09:14:00'))['weekly_best'];
    $at = digestAction()->handle(weeklyAt('2026-10-26 09:15:00'))['weekly_best'];

    // Then the stored time decides
    expect($before)->toBeNull()->and($at?->state)->toBe('completed');

    // And a malformed value falls back to 07:00 with a warning (key only)
    DB::table('digest_runs')->delete();
    DB::table('email_messages')->delete();
    Log::spy();
    retentionStoreRaw(SettingKey::DigestWeeklySendAtUtc, '25:61');
    expect(digestAction()->handle(weeklyAt('2026-10-26 06:59:00'))['weekly_best'])->toBeNull();
    expect(digestAction()->handle(weeklyAt('2026-10-26 07:00:00'))['weekly_best']?->state)->toBe('completed');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => $c === ['key' => 'digest.weekly_send_at_utc'])->twice();
});
