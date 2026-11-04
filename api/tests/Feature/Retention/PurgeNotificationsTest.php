<?php

use App\Actions\Retention\PurgeNotifications;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC'));
    $this->user = User::factory()->create();
});

it('deletes read and unread notifications older than the window and keeps the boundary and newer rows', function () {
    // Given rows a second older than, exactly at, and a second inside the 90 day cutoff, read and unread, plus a fresh one
    $cutoff = retentionNow()->subDays(90);
    $oldUnread = retentionBell($this->user->id, $cutoff->subSecond());
    $oldRead = retentionBell($this->user->id, $cutoff->subDays(30), read: true);
    $atBoundary = retentionBell($this->user->id, $cutoff);
    $inside = retentionBell($this->user->id, $cutoff->addSecond());
    $fresh = retentionBell($this->user->id, retentionNow()->subMinute());

    // When the purge runs
    $purged = app(PurgeNotifications::class)->purge(retentionNow());

    // Then both old rows (read or not) are gone and the rest stay
    expect($purged)->toBe(2);
    expect(DB::table('notifications')->pluck('id')->sort()->values()->all())->toBe(collect([$atBoundary, $inside, $fresh])->sort()->values()->all());
    expect(DB::table('notifications')->whereIn('id', [$oldUnread, $oldRead])->exists())->toBeFalse();
});

it('is idempotent and leaves the member alone', function () {
    // Given one old and one fresh row, purged once
    retentionBell($this->user->id, retentionNow()->subDays(200));
    $fresh = retentionBell($this->user->id, retentionNow()->subDay());
    expect(app(PurgeNotifications::class)->purge(retentionNow()))->toBe(1);

    // When it runs again
    $second = app(PurgeNotifications::class)->purge(retentionNow());

    // Then nothing more goes and the member and the fresh row remain
    expect($second)->toBe(0)->and(DB::table('notifications')->pluck('id')->all())->toBe([$fresh])->and(User::query()->count())->toBe(1);
});

it('moves the cutoff when the setting changes', function () {
    // Given a row 40 days old and the default window of 90 days
    $id = retentionBell($this->user->id, retentionNow()->subDays(40));
    expect(app(PurgeNotifications::class)->purge(retentionNow()))->toBe(0);

    // When an admin shortens the window to 30 days
    app(Settings::class)->set(SettingKey::RetentionNotificationsDays, 30, User::factory()->role(Role::Admin)->create());

    // Then the same row is now old enough
    expect(app(PurgeNotifications::class)->purge(retentionNow()))->toBe(1)->and(DB::table('notifications')->where('id', $id)->exists())->toBeFalse();
});

it('works through chunks, stops at the cap and continues next run, oldest first', function () {
    // Given seven old rows of rising age
    $ids = collect(range(1, 7))->map(fn (int $i) => retentionBell($this->user->id, retentionNow()->subDays(100 + $i)))->all();
    $purge = retentionPurger(PurgeNotifications::class, 2, 2);

    // When it runs twice (chunk of two, cap of two chunks)
    $first = $purge->purge(retentionNow());
    $firstCapped = $purge->capped;
    $survivors = DB::table('notifications')->pluck('id')->sort()->values()->all();
    $second = $purge->purge(retentionNow());

    // Then the first run took the four oldest and flagged the cap; the second finished the three others
    expect($first)->toBe(4)->and($firstCapped)->toBeTrue()->and($survivors)->toBe(collect([$ids[0], $ids[1], $ids[2]])->sort()->values()->all());
    expect($second)->toBe(3)->and($purge->capped)->toBeFalse()->and(DB::table('notifications')->count())->toBe(0);
});

it('keeps the running count when a later chunk fails', function () {
    // Given five old rows, chunks of two, and a purge whose second chunk throws
    foreach (range(1, 5) as $i) {
        retentionBell($this->user->id, retentionNow()->subDays(100 + $i));
    }
    $purge = new class(app(Settings::class), 2, 10) extends PurgeNotifications
    {
        private int $calls = 0;

        protected function purgeChunk(CarbonImmutable $now, int $limit): int
        {
            if (++$this->calls === 2) {
                DB::select('select 1/0');
            }

            return parent::purgeChunk($now, $limit);
        }
    };

    // When it runs
    // Then the failure surfaces, but the first chunk's two rows are committed and counted
    expect(fn () => $purge->purge(retentionNow()))->toThrow(QueryException::class);
    expect($purge->purgedSoFar)->toBe(2)->and(DB::table('notifications')->count())->toBe(3);
});

it('falls back to the default window, never to zero, for a corrupt stored value', function (mixed $stored) {
    // Given a stored value that bypassed validation, a row 60 days old (inside the default 90) and one 120 days old
    retentionStoreRaw(SettingKey::RetentionNotificationsDays, $stored);
    $inside = retentionBell($this->user->id, retentionNow()->subDays(60));
    $outside = retentionBell($this->user->id, retentionNow()->subDays(120));

    // When the purge runs
    app(PurgeNotifications::class)->purge(retentionNow());

    // Then the default (90 days) applied
    expect(DB::table('notifications')->pluck('id')->all())->toBe([$inside])->and(DB::table('notifications')->where('id', $outside)->exists())->toBeFalse();
})->with(['zero' => [0], 'negative' => [-1], 'one' => [1], 'below the floor' => [6], 'far above' => [10000], 'string' => ['x'], 'numeric string' => ['30'], 'float' => [30.5], 'array' => [[30]], 'null' => [null]]);

it('locks no user or product row and picks its rows in a materialised CTE with SKIP LOCKED', function () {
    // Given an old row
    retentionBell($this->user->id, retentionNow()->subDays(200));

    // When the purge runs with every statement recorded
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });
    app(PurgeNotifications::class)->purge(retentionNow());

    // Then only the notifications table is touched, and the pick is materialised, ordered, bounded and skips locked rows
    foreach ($sql as $statement) {
        expect($statement)->not->toMatch('/\b(users|products)\b/');
    }
    $picked = collect($sql)->first(fn (string $s) => str_starts_with($s, 'WITH picked'));
    expect($picked)->toContain('AS MATERIALIZED')->toContain('FOR UPDATE SKIP LOCKED')->toContain('ORDER BY created_at')->toContain('LIMIT');
});

it('touches at most one chunk of rows whatever plan the planner picks', function (array $off) {
    // Given twelve eligible old rows, a chunk of three, and a planner pushed towards a given join strategy
    foreach (range(1, 12) as $i) {
        retentionBell($this->user->id, retentionNow()->subDays(100 + $i));
    }
    DB::statement('ANALYZE notifications');
    retentionPlannerOff($off);

    // When one chunk runs
    $purged = retentionPurger(PurgeNotifications::class, 3, 1)->purge(retentionNow());

    // Then exactly three rows were deleted and nine remain
    expect($purged)->toBe(3)->and(DB::table('notifications')->count())->toBe(9);
})->with(retentionPlannerModes());

it('uses the created_at index for the select that picks the rows', function () {
    // Given a table with rows, analysed, and the SQL the purge really runs (captured)
    foreach (range(1, 60) as $i) {
        retentionBell($this->user->id, retentionNow()->subDays(100 + $i * 5));
    }
    DB::statement('ANALYZE notifications');
    $sql = retentionCaptureSql(fn () => app(PurgeNotifications::class)->purge(retentionNow()), 'WITH picked')[0] ?? '';

    // When its plan is read with scans and sorts that avoid indexes disabled
    $plan = retentionPlan($sql);

    // Then the picked-rows CTE is served by notifications_created_at_index, with no Sort node
    expect($plan)->toContain('notifications_created_at_index')->not->toContain('Seq Scan on notifications')->not->toContain('Sort');
});
