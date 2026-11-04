<?php

use App\Actions\Notification\Reconcile;
use App\Actions\Retention\PurgeEmailMessages;
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

it('purges finished rows older than the window and keeps the boundary, fresh rows and every waiting row, however old', function () {
    // Given finished rows a second older than the 90 day cutoff, rows exactly at it, fresh rows, and ancient waiting rows
    $old = retentionNow()->subDays(90)->subSecond();
    $oldSent = retentionMail($this->user->id, 'sent', $old);
    $oldFailed = retentionMail($this->user->id, 'failed', $old);
    $oldSuppressed = retentionMail($this->user->id, 'suppressed', $old);
    $atBoundary = retentionMail($this->user->id, 'sent', retentionNow()->subDays(90));
    $fresh = retentionMail($this->user->id, 'failed', retentionNow()->subDay());
    $ancientQueued = retentionMail($this->user->id, 'queued', retentionNow()->subDays(400));
    $ancientSending = retentionMail($this->user->id, 'sending', retentionNow()->subDays(400));

    // When the purge runs
    $purged = app(PurgeEmailMessages::class)->purge(retentionNow());

    // Then the three old finished rows are gone, everything else stays
    expect($purged)->toBe(3);
    expect(DB::table('email_messages')->pluck('id')->sort()->values()->all())->toBe(collect([$atBoundary, $fresh, $ancientQueued, $ancientSending])->sort()->values()->all());
    expect(DB::table('email_messages')->whereIn('id', [$oldSent, $oldFailed, $oldSuppressed])->exists())->toBeFalse();
});

it('is idempotent', function () {
    // Given one old and one fresh finished row, purged once
    retentionMail($this->user->id, 'sent', retentionNow()->subDays(200));
    $fresh = retentionMail($this->user->id, 'sent', retentionNow()->subDay());
    expect(app(PurgeEmailMessages::class)->purge(retentionNow()))->toBe(1);

    // When it runs again
    // Then nothing more goes
    expect(app(PurgeEmailMessages::class)->purge(retentionNow()))->toBe(0)->and(DB::table('email_messages')->pluck('id')->all())->toBe([$fresh]);
});

it('moves the cutoff when the setting changes, down to 30 days', function () {
    // Given a sent row 40 days old and the default window of 90 days
    $id = retentionMail($this->user->id, 'sent', retentionNow()->subDays(40));
    expect(app(PurgeEmailMessages::class)->purge(retentionNow()))->toBe(0);

    // When an admin shortens the window to 30 days
    app(Settings::class)->set(SettingKey::RetentionEmailMessagesDays, 30, User::factory()->role(Role::Admin)->create());

    // Then the row is old enough
    expect(app(PurgeEmailMessages::class)->purge(retentionNow()))->toBe(1)->and(DB::table('email_messages')->where('id', $id)->exists())->toBeFalse();
});

it('never purges a row younger than the reconcile window plus a day, whatever the setting says', function () {
    // Given a Settings that (impossibly) serves a 1 day window, and a sent row 2 days old (inside the 48 hour sweep window plus 24 hours)
    $settings = new class extends Settings
    {
        public function days(SettingKey $key): int
        {
            return 1;
        }
    };
    $young = retentionMail($this->user->id, 'sent', retentionNow()->subDays(2));
    $old = retentionMail($this->user->id, 'sent', retentionNow()->subHours(Reconcile::MIN_EMAIL_RETENTION_HOURS)->subSecond());

    // When the purge runs
    (new PurgeEmailMessages($settings))->purge(retentionNow());

    // Then the floor held: only the row beyond it was purged
    expect(DB::table('email_messages')->pluck('id')->all())->toBe([$young])->and($old)->not->toBe($young);
});

it('keeps the settings floor above the reconcile window and its safety margin', function () {
    // Given the documented range of the email window
    [$min] = SettingKey::RetentionEmailMessagesDays->dayRange();

    // When compared with the sweep window plus a day
    // Then the smallest allowed window is longer
    expect($min * 24)->toBeGreaterThanOrEqual(Reconcile::MIN_EMAIL_RETENTION_HOURS);
});

it('works through chunks, stops at the cap and continues next run', function () {
    // Given seven old rows, a chunk of two and a cap of two chunks
    foreach (range(1, 7) as $i) {
        retentionMail($this->user->id, 'sent', retentionNow()->subDays(100 + $i));
    }
    $purge = retentionPurger(PurgeEmailMessages::class, 2, 2);

    // When it runs twice
    $first = $purge->purge(retentionNow());
    $firstCapped = $purge->capped;
    $second = $purge->purge(retentionNow());

    // Then four then three rows went
    expect($first)->toBe(4)->and($firstCapped)->toBeTrue()->and($second)->toBe(3)->and($purge->capped)->toBeFalse();
});

it('keeps the running count when a later chunk fails', function () {
    // Given five old rows and a purge whose second chunk throws
    foreach (range(1, 5) as $i) {
        retentionMail($this->user->id, 'sent', retentionNow()->subDays(100 + $i));
    }
    $purge = new class(app(Settings::class), 2, 10) extends PurgeEmailMessages
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
    // Then the failure surfaces and the first chunk is committed and counted
    expect(fn () => $purge->purge(retentionNow()))->toThrow(QueryException::class);
    expect($purge->purgedSoFar)->toBe(2)->and(DB::table('email_messages')->count())->toBe(3);
});

it('falls back to the default window, never to zero, for a corrupt stored value', function (mixed $stored) {
    // Given a stored value that bypassed validation, a row 60 days old (inside the default 90) and one 120 days old
    retentionStoreRaw(SettingKey::RetentionEmailMessagesDays, $stored);
    $inside = retentionMail($this->user->id, 'sent', retentionNow()->subDays(60));
    $outside = retentionMail($this->user->id, 'sent', retentionNow()->subDays(120));

    // When the purge runs
    app(PurgeEmailMessages::class)->purge(retentionNow());

    // Then the default applied
    expect(DB::table('email_messages')->pluck('id')->all())->toBe([$inside])->and(DB::table('email_messages')->where('id', $outside)->exists())->toBeFalse();
})->with(['zero' => [0], 'negative' => [-1], 'one' => [1], 'below the floor' => [29], 'far above' => [10000], 'string' => ['x'], 'numeric string' => ['30'], 'float' => [30.5], 'array' => [[30]], 'null' => [null]]);

it('locks no user or product row, and its pick repeats the partial index predicate and skips locked rows', function () {
    // Given an old row
    retentionMail($this->user->id, 'sent', retentionNow()->subDays(200));

    // When the purge runs with every statement recorded
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });
    app(PurgeEmailMessages::class)->purge(retentionNow());

    // Then only email_messages is touched and the statement carries the index predicate text
    foreach ($sql as $statement) {
        expect($statement)->not->toMatch('/\b(users|products)\b/');
    }
    $picked = collect($sql)->first(fn (string $s) => str_starts_with($s, 'WITH picked'));
    expect($picked)->toContain("status IN ('sent', 'failed', 'suppressed')")->toContain('AS MATERIALIZED')->toContain('FOR UPDATE SKIP LOCKED');
});

it('touches at most one chunk of rows whatever plan the planner picks', function (array $off) {
    // Given twelve eligible old rows, a chunk of three, and a planner pushed towards a given join strategy
    foreach (range(1, 12) as $i) {
        retentionMail($this->user->id, 'sent', retentionNow()->subDays(100 + $i));
    }
    DB::statement('ANALYZE email_messages');
    retentionPlannerOff($off);

    // When one chunk runs
    $purged = retentionPurger(PurgeEmailMessages::class, 3, 1)->purge(retentionNow());

    // Then exactly three rows were deleted and nine remain
    expect($purged)->toBe(3)->and(DB::table('email_messages')->count())->toBe(9);
})->with(retentionPlannerModes());

it('uses the terminal-state partial index for the select that picks the rows', function () {
    // Given a table with finished rows, a few waiting rows, analysed, and the SQL the purge really runs (captured)
    foreach (range(1, 60) as $i) {
        retentionMail($this->user->id, 'sent', retentionNow()->subDays(100 + $i * 5));
    }
    retentionMail($this->user->id, 'queued', retentionNow()->subDays(500));
    DB::statement('ANALYZE email_messages');
    $sql = retentionCaptureSql(fn () => app(PurgeEmailMessages::class)->purge(retentionNow()), 'WITH picked')[0] ?? '';

    // When its plan is read with scans and sorts that avoid indexes disabled
    $plan = retentionPlan($sql);

    // Then the picked-rows CTE is served by email_messages_terminal_created_index, with no Sort node
    expect($plan)->toContain('email_messages_terminal_created_index')->not->toContain('Sort');
});
