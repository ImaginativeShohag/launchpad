<?php

use App\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    antiCheatFreeze();
    $reviewer = signedInStaff(Role::Reviewer);
    $this->product = antiCheatLiveProduct();
    $now = antiCheatNow();

    // Several thousand rows, so the planner has real statistics to choose from.
    $users = [];
    for ($i = 0; $i < 3000; $i++) {
        $id = strtolower((string) Str::ulid());
        $users[] = [
            'id' => $id, 'handle' => 'idx-'.$id, 'name' => 'Idx', 'email' => 'idx-'.$id.'@example.test', 'role' => 'member',
            'risk_score' => $i % 3 === 0 ? 1 + $i % 100 : 0, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
    foreach (array_chunk($users, 500) as $chunk) {
        DB::table('users')->insert($chunk);
    }
    $this->userIds = array_column($users, 'id');

    $signals = [];
    foreach ($this->userIds as $i => $id) {
        $reviewed = $i % 3 === 0;
        $signals[] = [
            'id' => strtolower((string) Str::ulid()), 'kind' => 'shared_device', 'user_id' => $id, 'product_id' => $this->product->id, 'weight' => $i % 101,
            'details' => '{}', 'dedupe_key' => 'idx:'.$i, 'detected_at' => $now->subMinutes($i % 700)->toDateTimeString().'+00',
            'reviewed_by' => $reviewed ? $reviewer->id : null, 'reviewed_at' => $reviewed ? $now->subMinutes($i % 500)->toDateTimeString().'+00' : null,
            'outcome' => $reviewed ? 'confirmed' : null, 'review_note' => null,
        ];
    }
    foreach (array_chunk($signals, 500) as $chunk) {
        DB::table('risk_signals')->insert($chunk);
    }

    $votes = [];
    foreach ($this->userIds as $i => $id) {
        $votes[] = [
            'id' => strtolower((string) Str::ulid()), 'user_id' => $id, 'product_id' => $this->product->id, 'status' => 'counted',
            'voted_at' => $now->subSeconds($i % 900)->toDateTimeString().'+00', 'toggle_count' => 0, 'ip_hash' => antiCheatHash('i'.($i % 50)), 'device_hash' => antiCheatHash('d'.($i % 50)), 'created_at' => $now,
        ];
    }
    foreach (array_chunk($votes, 500) as $chunk) {
        DB::table('votes')->insert($chunk);
    }

    foreach (['users', 'risk_signals', 'votes'] as $table) {
        DB::statement("ANALYZE {$table}");
    }
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
});

/**
 * The main (ordered) statement the endpoint ran against a table, with its bindings substituted.
 */
function antiCheatIndexMainSql(string $path, array $query, string $table): string
{
    $captured = [];
    DB::listen(function ($q) use (&$captured, $table): void {
        if (preg_match('/^\s*select\b/i', $q->sql) === 1 && str_contains($q->sql, 'order by') && preg_match('/from "'.$table.'"/', $q->sql) === 1) {
            $captured[] = DB::getQueryGrammar()->substituteBindingsIntoRawSql($q->sql, $q->bindings);
        }
    });
    antiCheatGet($path, $query)->assertOk();

    expect($captured)->toHaveCount(1);

    return $captured[0];
}

function antiCheatIndexPlanOf(string $sql): string
{
    return collect(DB::select('EXPLAIN '.$sql))->pluck('QUERY PLAN')->implode("\n");
}

/** A full Sort node (an Incremental Sort is a different line). */
function antiCheatIndexHasFullSort(string $plan): bool
{
    return preg_match('/^\s*(->\s+)?Sort\s+\(cost/m', $plan) === 1;
}

it('serves the open queue, first page and next page, by the open-queue index without a sort', function () {
    // Given thousands of signals; the real SQL of page one and of a page after a cursor
    $first = antiCheatIndexMainSql('risk-signals', ['per_page' => 25], 'risk_signals');
    $page = antiCheatGet('risk-signals', ['per_page' => 25])->assertOk();
    $second = antiCheatIndexMainSql('risk-signals', ['per_page' => 25, 'cursor' => $page->json('meta.next_cursor')], 'risk_signals');

    // When planned with seq scans, bitmap scans and sorts off
    // Then both walk risk_signals_open_queue_index in order
    foreach ([$first, $second] as $sql) {
        $plan = antiCheatIndexPlanOf($sql);
        expect($plan)->toContain('risk_signals_open_queue_index')->not->toContain('Seq Scan');
        expect(antiCheatIndexHasFullSort($plan))->toBeFalse();
    }
});

it('serves the reviewed list by the reviewed index and the all list by the detected index, without a sort', function (string $status, string $index) {
    // Given thousands of signals
    $first = antiCheatIndexMainSql('risk-signals', ['status' => $status, 'per_page' => 25], 'risk_signals');
    $page = antiCheatGet('risk-signals', ['status' => $status, 'per_page' => 25])->assertOk();
    $second = antiCheatIndexMainSql('risk-signals', ['status' => $status, 'per_page' => 25, 'cursor' => $page->json('meta.next_cursor')], 'risk_signals');

    // When planned with the alternatives off
    // Then the matching index is walked in order
    foreach ([$first, $second] as $sql) {
        $plan = antiCheatIndexPlanOf($sql);
        expect($plan)->toContain($index)->not->toContain('Seq Scan');
        expect(antiCheatIndexHasFullSort($plan))->toBeFalse();
    }
})->with([['reviewed', 'risk_signals_reviewed_index'], ['all', 'risk_signals_detected_index']]);

it('serves the members list by the partial risk-score index without a sort', function () {
    // Given thousands of members, a third with a score
    $first = antiCheatIndexMainSql('risk/members', ['min_score' => 1, 'per_page' => 25], 'users');
    $page = antiCheatGet('risk/members', ['min_score' => 1, 'per_page' => 25])->assertOk();
    $second = antiCheatIndexMainSql('risk/members', ['min_score' => 1, 'per_page' => 25, 'cursor' => $page->json('meta.next_cursor')], 'users');

    // When planned with the alternatives off
    // Then users_risk_score_index is walked in order
    foreach ([$first, $second] as $sql) {
        $plan = antiCheatIndexPlanOf($sql);
        expect($plan)->toContain('users_risk_score_index')->not->toContain('Seq Scan');
        expect(antiCheatIndexHasFullSort($plan))->toBeFalse();
    }
});

it('serves the votes list by the product-and-time index: no seq scan, no full sort (an incremental sort is fine)', function () {
    // Given thousands of votes on one product
    $path = "products/{$this->product->id}/votes";
    $first = antiCheatIndexMainSql($path, ['per_page' => 25], 'votes');
    $page = antiCheatGet($path, ['per_page' => 25])->assertOk();
    $second = antiCheatIndexMainSql($path, ['per_page' => 25, 'cursor' => $page->json('meta.next_cursor')], 'votes');

    // When planned with the alternatives off
    // Then votes_product_id_voted_at_index is used
    foreach ([$first, $second] as $sql) {
        $plan = antiCheatIndexPlanOf($sql);
        expect($plan)->toContain('votes_product_id_voted_at_index')->not->toContain('Seq Scan');
        expect(antiCheatIndexHasFullSort($plan))->toBeFalse();
    }
});
