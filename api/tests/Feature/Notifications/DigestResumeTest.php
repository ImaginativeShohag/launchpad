<?php

use App\Enums\SettingKey;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/DigestFixtures.php';
require_once __DIR__.'/../../Support/RetentionFixtures.php';

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-21 07:00:00 UTC'));
    digestFreeze('2026-10-20', [digestProduct('2026-10-20')]);
    // The product's maker is a verified member too; every test counts recipients from the users table.
    $this->now = CarbonImmutable::parse('2026-10-21 07:00:00', 'UTC');
});

function digestEligibleCount(): int
{
    return DB::table('users')->whereNotNull('email_verified_at')->whereNull('suspended_at')->whereNull('deleted_at')->count();
}

it('answers an already finished run with one lookup and no other query', function () {
    // Given a digest that has run to completion
    digestUsers(3);
    digestAction()->handle($this->now);
    expect(digestRun('daily_top5', '2026-10-20')['completed_at'])->not->toBeNull();

    // When the job runs again (the next tick)
    DB::flushQueryLog();
    DB::enableQueryLog();
    $result = digestAction()->handle($this->now)['daily_top5'];
    $queries = collect(DB::getQueryLog())->pluck('query')->reject(fn (string $sql) => str_contains($sql, '"settings"'))->values()->all();
    DB::disableQueryLog();

    // Then it is already done, after one digest_runs lookup per kind and nothing else (settings reads aside)
    expect($result->state)->toBe('already_done')->and($result->completed)->toBeTrue();
    expect($queries)->toHaveCount(2);
    foreach ($queries as $sql) {
        expect($sql)->toContain('from "digest_runs"');
    }
});

it('resumes at the cursor after a crash between chunks and ends with one row per member', function () {
    // Given five members, a chunk of two, and a crash after the first chunk
    Exceptions::fake();
    digestUsers(5);
    $crash = digestAction(2, function () {
        throw new RuntimeException('crash after chunk one');
    });

    // When the first run crashes
    $first = $crash->handle($this->now);

    // Then the failure was reported, the run is open and its cursor sits at the last user of the first chunk
    expect($first['daily_top5'])->toBeNull()->and($crash->failed)->toBe(1)->and($crash->failedKinds)->toBe(['daily_top5']);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'crash after chunk one');
    $run = digestRun('daily_top5', '2026-10-20');
    $firstTwo = DB::table('email_messages')->where('type', 'daily_top5')->orderBy('user_id')->pluck('user_id')->all();
    expect($run['completed_at'])->toBeNull()->and($run['enqueued'])->toBe(2)->and($run['last_user_id'])->toBe($firstTwo[1])->and($firstTwo)->toHaveCount(2);

    // And when the next tick runs without the crash
    $second = digestAction(2)->handle($this->now)['daily_top5'];

    // Then it resumed, finished, and every eligible member has exactly one row
    expect($second->state)->toBe('resumed')->and($second->completed)->toBeTrue()->and($second->enqueued)->toBe(digestEligibleCount());
    $keys = DB::table('email_messages')->where('type', 'daily_top5')->pluck('idempotency_key');
    expect($keys)->toHaveCount(digestEligibleCount())->and($keys->unique())->toHaveCount(digestEligibleCount());
    expect(digestRun('daily_top5', '2026-10-20')['completed_at'])->not->toBeNull();
});

it('creates no duplicate when the same keys already exist (a racing runner)', function () {
    // Given three members, two of whom already have their row for this period
    $ids = digestUsers(3);
    foreach (array_slice($ids, 0, 2) as $id) {
        DB::table('email_messages')->insert([
            'id' => strtolower((string) Str::ulid()), 'user_id' => $id, 'type' => 'daily_top5', 'idempotency_key' => 'daily_top5:2026-10-20:'.$id,
            'to_email' => 'x@example.com', 'payload' => '{}', 'status' => 'queued', 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // When the digest is queued
    $result = digestAction()->handle($this->now)['daily_top5'];

    // Then the existing rows stay as they were, no duplicate appears, and only the new rows are counted
    $total = digestEligibleCount();
    expect(DB::table('email_messages')->where('type', 'daily_top5')->count())->toBe($total - 0);
    expect($result->enqueued)->toBe($total - 2);
    expect(DB::table('email_messages')->where('type', 'daily_top5')->where('to_email', 'x@example.com')->count())->toBe(2);
});

it('resumes a crashed run after midnight from its cursor when it is younger than 18 hours, without duplicates', function () {
    // Given four members, a chunk of two, a frozen next day, and a crash after the first chunk at 23:50 on the 21st
    Exceptions::fake();
    digestUsers(4);
    digestFreeze('2026-10-21', [digestProduct('2026-10-21')]);
    $this->travelTo(new DateTimeImmutable('2026-10-21 23:50:00 UTC'));
    $crash = digestAction(2, function () {
        throw new RuntimeException('crash at 23:50');
    });
    $crash->handle(digestAt('2026-10-21 23:50:00'));
    $cursor = digestRun('daily_top5', '2026-10-20')['last_user_id'];
    expect(digestRun('daily_top5', '2026-10-20')['completed_at'])->toBeNull()->and($cursor)->not->toBeNull();

    // When the next scheduled tick runs at 00:20 on the 22nd
    $this->travelTo(new DateTimeImmutable('2026-10-22 00:20:00 UTC'));
    $action = digestAction(2);
    $result = $action->handle(digestAt('2026-10-22 00:20:00'))['daily_top5'];

    // Then the old run finished from its cursor under its own period; nobody got two rows; nothing counted as failed
    expect($action->failed)->toBe(0)->and($result->period)->toBe('2026-10-20')->and($result->state)->toBe('resumed')->and($result->completed)->toBeTrue();
    $keys = DB::table('email_messages')->where('type', 'daily_top5')->pluck('idempotency_key');
    expect($keys)->toHaveCount(digestEligibleCount())->and($keys->unique())->toHaveCount(digestEligibleCount());
    expect($keys->every(fn (string $k) => str_starts_with($k, 'daily_top5:2026-10-20:')))->toBeTrue();
    expect(digestRun('daily_top5', '2026-10-20')['completed_at'])->not->toBeNull();
});

it('abandons a run older than 18 hours once: closed, logged, failed, and the new period gets its own run', function () {
    // Given an unfinished run started at 07:00 on the 21st, and the next day frozen
    Exceptions::fake();
    Log::spy();
    digestUsers(4);
    digestFreeze('2026-10-21', [digestProduct('2026-10-21')]);
    digestAction(2, function () {
        throw new RuntimeException('crash');
    })->handle($this->now);
    $sent = DB::table('email_messages')->where('type', 'daily_top5')->count();

    // When the tick 24 hours later runs
    $later = CarbonImmutable::parse('2026-10-22 07:30:00', 'UTC');
    $this->travelTo(new DateTimeImmutable('2026-10-22 07:30:00 UTC'));
    $action = digestAction();
    $result = $action->handle($later)['daily_top5'];

    // Then the old run was closed and counted as a failure with a warning (kind and period only), while the 21st got its own digest
    expect($action->failed)->toBe(1)->and($action->failedKinds)->toBe(['daily_top5']);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => $c === ['kind' => 'daily_top5', 'period' => '2026-10-20'])->once();
    expect(digestRun('daily_top5', '2026-10-20')['completed_at'])->not->toBeNull();
    expect($result->period)->toBe('2026-10-21')->and($result->state)->toBe('completed');
    expect(DB::table('email_messages')->where('type', 'daily_top5')->where('idempotency_key', 'like', 'daily_top5:2026-10-20:%')->count())->toBe($sent);

    // And the next tick does not report it again
    $again = digestAction();
    $again->handle($later->addMinutes(10));
    expect($again->failed)->toBe(0);
});

it('queues 1,200 members in three chunks with a fixed number of queries per chunk', function () {
    // Given 1,200 eligible members (plus the product's maker) and, separately, a run for a small set
    digestUsers(1199);
    expect(digestEligibleCount())->toBe(1200);
    app(Settings::class)->timeOfDay(SettingKey::DigestDailySendAtUtc); // warm the settings cache: it is not part of a chunk
    $queries = 0;
    DB::listen(function ($query) use (&$queries) {
        $queries++;
    });

    // When the digest is queued
    $queries = 0;
    $result = digestAction()->handle($this->now)['daily_top5'];
    $large = $queries;

    // Then three chunks (500, 500, 200) did it, with the whole run costing a fixed number of queries per chunk
    expect($result->enqueued)->toBe(1200)->and($result->state)->toBe('completed');
    expect(DB::table('email_messages')->where('type', 'daily_top5')->count())->toBe(1200);
    expect(digestRun('daily_top5', '2026-10-20')['enqueued'])->toBe(1200);

    // And a run for 10 members uses exactly two chunks' worth of queries fewer: no per-member query
    DB::table('email_messages')->delete();
    DB::table('digest_runs')->delete();
    DB::table('users')->whereNotIn('id', DB::table('users')->orderBy('id')->limit(10)->pluck('id'))->update(['email_verified_at' => null]);
    $queries = 0;
    $small = digestAction()->handle($this->now)['daily_top5'];
    expect($small->enqueued)->toBe(10);
    $perChunk = 4; // select the run FOR UPDATE, select the members, insert the rows, update the cursor
    expect($large - $queries)->toBe(2 * $perChunk);
});

it('reads the recipients of a chunk in primary key order without a sort', function () {
    // Given an analysed users table with a couple of thousand members
    digestUsers(2000);
    DB::statement('ANALYZE users');
    DB::statement('ANALYZE email_preferences');

    // When the chunk query the digest really runs is captured and explained
    $sql = retentionCaptureSql(fn () => digestAction(500)->handle($this->now), 'SELECT u.id')[0] ?? null;
    expect($sql)->not->toBeNull();
    $plan = retentionPlan(str_replace(' FOR SHARE OF u', '', $sql));

    // Then it locks the members for share in id order (a concurrent account deletion finishes first), walks the primary key and needs no Sort node
    expect($sql)->toContain('ORDER BY u.id LIMIT 500 FOR SHARE OF u');
    expect($plan)->toContain('users_pkey')->not->toContain('Sort');
});
