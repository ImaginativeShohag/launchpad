<?php

use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\Vote;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

const RETENTION_NOW = '2026-10-21 12:00:00';

function retentionNow(): CarbonImmutable
{
    return CarbonImmutable::parse(RETENTION_NOW, 'UTC');
}

/** Stores a raw JSON value for a setting, the way a direct database edit would (no validation), and clears the cache. */
function retentionStoreRaw(SettingKey $key, mixed $value): void
{
    DB::table('settings')->updateOrInsert(['key' => $key->value], ['value' => json_encode($value), 'updated_at' => now()]);
    Cache::flush();
}

function retentionAuthEvent(string|CarbonImmutable $createdAt, array $attributes = []): string
{
    $id = (string) Str::ulid();
    DB::table('auth_events')->insert([
        'id' => $id, 'user_id' => null, 'event' => 'login_failed', 'ip_hash' => str_repeat('a', 64),
        'device_hash' => str_repeat('b', 64), 'user_agent' => 'Test', 'created_at' => (string) $createdAt, ...$attributes,
    ]);

    return $id;
}

/** An audit_logs row (inserted directly: the model would stamp created_at itself) that still holds both network columns. */
function retentionAuditLog(string|CarbonImmutable $createdAt, array $attributes = []): string
{
    $id = (string) Str::ulid();
    DB::table('audit_logs')->insert([
        'id' => $id, 'actor_id' => null, 'action' => 'test.action', 'subject_type' => 'product', 'subject_id' => (string) Str::ulid(),
        'meta' => json_encode(['reason' => 'kept']), 'ip_hash' => str_repeat('c', 64), 'user_agent' => 'Test Agent',
        'created_at' => (string) $createdAt, ...$attributes,
    ]);

    return $id;
}

function retentionSnapshot(Product $product, string|CarbonImmutable $capturedAt, int $rank = 1): void
{
    DB::table('rank_snapshots')->insert(['product_id' => $product->id, 'captured_at' => (string) $capturedAt, 'rank' => $rank, 'score' => 1.5]);
}

/** A vote (with all three signals) on a product that launched on $launchDay. */
function retentionVote(string $launchDay, array $attributes = []): Vote
{
    $product = Product::factory()->live($launchDay)->create();

    return Vote::factory()->create(['product_id' => $product->id, ...$attributes]);
}

/** @return list<string> the SQL (bindings substituted) of every statement the callback ran that starts with $prefix */
function retentionCaptureSql(callable $callback, string $prefix): array
{
    $captured = [];
    DB::listen(function ($query) use (&$captured, $prefix) {
        if (str_starts_with(ltrim($query->sql), $prefix)) {
            $captured[] = DB::getQueryGrammar()->substituteBindingsIntoRawSql($query->sql, $query->bindings);
        }
    });
    $callback();

    return $captured;
}

/** The plan of $sql with the planner pushed to use indexes, so the test shows what an index CAN serve on a tiny table. */
function retentionPlan(string $sql): string
{
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }

    return collect(DB::select('EXPLAIN '.$sql))->pluck('QUERY PLAN')->implode("\n");
}

/**
 * A purge built straight from its constructor: the chunk sizes can never be swallowed by a container binding, and the
 * test states its own numbers.
 *
 * @template T of \App\Actions\Retention\RetentionPurge
 *
 * @param  class-string<T>  $class
 * @return T
 */
function retentionPurger(string $class, int $chunk, int $maxChunks)
{
    return new $class(app(Settings::class), $chunk, $maxChunks);
}

/**
 * Planner configurations that change how a statement with a sub-select is executed (a nested-loop semi join may
 * re-run an un-materialised LIMIT subquery). Each value is the list of `enable_*` settings switched off.
 *
 * @return array<string, array{0: list<string>}>
 */
function retentionPlannerModes(): array
{
    return [
        'default plan' => [[]],
        'nested loops off' => [['enable_nestloop']],
        'only nested loops' => [['enable_hashjoin', 'enable_mergejoin']],
        'no hash aggregate, no material' => [['enable_hashagg', 'enable_material']],
        'no index scans' => [['enable_indexscan', 'enable_indexonlyscan']],
        'nested loop semi join, nothing to cache or dedupe' => [['enable_hashjoin', 'enable_mergejoin', 'enable_hashagg', 'enable_material', 'enable_sort', 'enable_memoize']],
    ];
}

/** @param  list<string>  $off  `enable_*` planner settings to switch off for the rest of the test's transaction */
function retentionPlannerOff(array $off): void
{
    foreach ($off as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
}

/** A bell row (raw insert) created $createdAt; read or not. Returns its id. */
function retentionBell(string $userId, string|CarbonImmutable $createdAt, bool $read = false): string
{
    $id = (string) Str::uuid7();
    DB::table('notifications')->insert([
        'id' => $id, 'user_id' => $userId, 'type' => 'review_approved', 'data' => '{"message_key":"notifications.review_approved"}', 'dedupe_key' => 'ret:'.$id,
        'read_at' => $read ? (string) $createdAt : null, 'created_at' => (string) $createdAt, 'updated_at' => (string) $createdAt,
    ]);

    return $id;
}

/** An outbox row (raw insert) in $status created $createdAt; the due and sent columns satisfy the state CHECKs. Returns its id. */
function retentionMail(string $userId, string $status, string|CarbonImmutable $createdAt): string
{
    $id = strtolower((string) Str::ulid());
    $waiting = in_array($status, ['queued', 'sending'], true);
    DB::table('email_messages')->insert([
        'id' => $id, 'user_id' => $userId, 'type' => 'review_outcome', 'idempotency_key' => 'ret:'.$id, 'to_email' => 'keep@example.com', 'payload' => '{}',
        'status' => $status, 'attempts' => 0, 'next_attempt_at' => $waiting ? (string) $createdAt : null, 'last_error' => null, 'provider_message_id' => null,
        'sent_at' => $status === 'sent' ? (string) $createdAt : null, 'created_at' => (string) $createdAt, 'updated_at' => (string) $createdAt,
    ]);

    return $id;
}

/** A digest run row (raw insert) for $period (Y-m-d). */
function retentionDigestRun(string $period, string $kind = 'daily_top5'): void
{
    DB::table('digest_runs')->insert(['kind' => $kind, 'period_date' => $period, 'product_ids' => '[]', 'last_user_id' => null, 'enqueued' => 0, 'started_at' => $period.' 07:00:00+00', 'completed_at' => $period.' 07:01:00+00']);
}
