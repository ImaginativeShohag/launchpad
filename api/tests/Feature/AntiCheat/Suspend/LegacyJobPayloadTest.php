<?php

use App\Jobs\VoidDeletedMemberVotes;
use App\Jobs\VoidMemberVotes;
use App\Models\Product;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** The payload of a job queued by the class before its rename (captured from a real `jobs` row), with the user id swapped in. */
function antiCheatLegacyPayload(string $userId): string
{
    $raw = (string) file_get_contents(base_path('tests/Fixtures/legacy-void-deleted-member-votes-payload.json'));

    return str_replace('00000000000000000000000000', $userId, $raw);
}

it('loads the captured legacy command through the deprecated subclass with the default reason', function () {
    // Given the command string of a job queued before the rename
    $userId = (string) Str::ulid();
    $payload = json_decode(antiCheatLegacyPayload($userId), true, flags: JSON_THROW_ON_ERROR);

    // When it is unserialized
    $job = unserialize($payload['data']['command']);

    // Then it is the deprecated subclass of the renamed job, deleted-only by default, keyed the new way
    expect($payload['data']['commandName'])->toBe('App\\Jobs\\VoidDeletedMemberVotes');
    expect($job)->toBeInstanceOf(VoidDeletedMemberVotes::class)->toBeInstanceOf(VoidMemberVotes::class)
        ->and($job->userId)->toBe($userId)->and($job->reason)->toBe('account_deleted')->and($job->uniqueId())->toBe('account_deleted:'.$userId);
    expect((new ReflectionClass(VoidDeletedMemberVotes::class))->getDocComment())->toContain('@deprecated');
});

it('runs a legacy payload through a real queue worker and voids a deleted member votes', function () {
    // Given a deleted member with an active vote today, and the legacy payload in the jobs table of the database queue
    config(['queue.default' => 'database']);
    $this->travelTo(new DateTimeImmutable('2026-10-19 10:30:00 UTC'));
    $member = User::factory()->create(['deleted_at' => now()]);
    $product = Product::factory()->live(now('UTC')->toDateString())->create();
    $vote = Vote::factory()->create(['user_id' => $member->id, 'product_id' => $product->id, 'voted_at' => now()->startOfSecond()]);
    DB::table('products')->where('id', $product->id)->increment('votes_count');
    $legacy = antiCheatLegacyPayload($member->id);
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => $legacy, 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);

    // When a real worker takes one job
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

    // Then the job ran as the renamed class' behaviour: the vote is voided with the default reason and nothing failed
    expect($vote->fresh()->status->value)->toBe('voided')->and($vote->fresh()->voided_reason)->toBe('account_deleted')->and($product->fresh()->votes_count)->toBe(0);
    // (the void's own VoteVoided broadcast listener queued a follow-up job: only the legacy row itself must be gone)
    $left = DB::table('jobs')->pluck('payload')->map(fn (string $p) => json_decode($p, true)['displayName'])->all();
    expect($left)->not->toContain('App\Jobs\VoidDeletedMemberVotes')->and(DB::table('failed_jobs')->count())->toBe(0);
});
