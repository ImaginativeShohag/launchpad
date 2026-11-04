<?php

use App\Actions\Email\ClaimDueEmails;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Rows in mixed states over many users, analysed, so the planner has statistics to choose from. */
function outboxSeedMixed(int $count): void
{
    $users = [];
    foreach (range(1, 20) as $_) {
        $users[] = User::factory()->create()->id;
    }
    $statuses = ['queued', 'queued', 'sending', 'sent', 'sent', 'failed', 'suppressed'];
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $status = $statuses[$i % count($statuses)];
        $due = in_array($status, ['queued', 'sending'], true) ? CarbonImmutable::parse('2026-10-20 00:00:00', 'UTC')->addSeconds($i)->format('Y-m-d H:i:s.uP') : null;
        $rows[] = [
            'id' => strtolower((string) Str::ulid()), 'user_id' => $users[$i % 20], 'type' => 'product_live', 'idempotency_key' => "idx:{$i}",
            'to_email' => 'a@example.com', 'payload' => '{}', 'status' => $status, 'attempts' => $status === 'queued' ? 0 : 1, 'next_attempt_at' => $due,
            'last_error' => null, 'provider_message_id' => null, 'sent_at' => $status === 'sent' ? '2026-10-20 00:00:00+00' : null,
            'created_at' => '2026-10-20 00:00:00+00', 'updated_at' => '2026-10-20 00:00:00+00',
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('email_messages')->insert($chunk);
    }
    DB::statement('ANALYZE email_messages');
}

it('serves the claim query from the partial due index without a sort', function () {
    // Given thousands of outbox rows in mixed states
    outboxSeedMixed(4200);
    $captured = null;
    DB::listen(function ($query) use (&$captured) {
        if (str_contains(strtolower($query->sql), 'with picked as materialized') && $captured === null) {
            $captured = DB::getQueryGrammar()->substituteBindingsIntoRawSql($query->sql, $query->bindings);
        }
    });
    app(ClaimDueEmails::class)->handle(CarbonImmutable::parse('2026-10-22 00:00:00', 'UTC'), 50);

    // When the application's real SQL is explained with sequential scans, bitmap scans and sorts disabled
    foreach (['enable_seqscan', 'enable_bitmapscan', 'enable_sort'] as $setting) {
        DB::statement("SET LOCAL {$setting} = off");
    }
    $plan = collect(DB::select('EXPLAIN '.$captured))->pluck('QUERY PLAN')->implode("\n");

    // Then it walks email_messages_due_index and has no Sort node
    expect($plan)->toContain('email_messages_due_index')->not->toContain('Sort');
});
