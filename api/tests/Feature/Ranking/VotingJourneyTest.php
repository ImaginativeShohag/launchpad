<?php

use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

it('carries a launch day from the first vote to its frozen page and the all-time top', function () {
    // Given two products launching on 2026-10-20, a maker, and two voters
    $this->travelTo(new DateTimeImmutable('2026-10-20 10:00:00 UTC'));
    $maker = User::factory()->create();
    $first = Product::factory()->live('2026-10-20')->create(['maker_id' => $maker->id]);
    $second = Product::factory()->live('2026-10-20')->create(['maker_id' => $maker->id]);
    $voterOne = User::factory()->create();
    $voterTwo = User::factory()->create();

    // When the voters cast (one withdraws and casts again), the scores are recomputed and the ranks snapshotted
    Sanctum::actingAs($voterOne);
    $this->putJson("/api/v1/products/{$first->id}/vote")->assertOk()->assertJsonPath('data.votes_count', 1);
    $this->deleteJson("/api/v1/products/{$first->id}/vote")->assertOk()->assertJsonPath('data.votes_count', 0);
    $this->putJson("/api/v1/products/{$first->id}/vote")->assertOk()->assertJsonPath('data.votes_count', 1);
    Sanctum::actingAs($voterTwo);
    $this->putJson("/api/v1/products/{$first->id}/vote")->assertOk()->assertJsonPath('data.votes_count', 2);
    $this->putJson("/api/v1/products/{$second->id}/vote")->assertOk()->assertJsonPath('data.votes_count', 1);
    Sanctum::actingAs($maker);
    $this->putJson("/api/v1/products/{$first->id}/vote")->assertForbidden()->assertJsonPath('code', 'own_product');
    $this->artisan('launchpad:recompute-hot-scores')->assertSuccessful();
    $this->artisan('launchpad:snapshot-ranks')->assertSuccessful();
    $topic = Topic::factory()->create();
    $first->topics()->sync([$topic->id]);

    // Then today's page ranks them by the live score and tells the voter what they voted for
    Sanctum::actingAs($voterTwo);
    $today = $this->getJson('/api/v1/today')->assertOk();
    expect(collect($today->json('data'))->map(fn (array $item) => [$item['id'], $item['rank'], $item['votes_count'], $item['has_voted']])->all())
        ->toBe([[$first->id, 1, 2, true], [$second->id, 2, 1, true]]);

    // And the snapshot recorded both ranks
    expect(DB::table('rank_snapshots')->orderBy('rank')->get(['product_id', 'rank'])->map(fn ($row) => [$row->product_id, $row->rank])->all())
        ->toBe([[$first->id, 1], [$second->id, 2]]);

    // And after midnight the day is frozen, voting is closed, and the frozen page and the all-time top agree
    $this->travelTo(new DateTimeImmutable('2026-10-21 00:02:00 UTC'));
    $this->artisan('launchpad:freeze-day')->assertSuccessful();
    expect(DB::table('daily_rankings')->where('launch_date', '2026-10-20')->count())->toBe(2);
    $this->putJson("/api/v1/products/{$second->id}/vote")->assertStatus(409)->assertJsonPath('code', 'voting_closed');
    $day = $this->getJson('/api/v1/days/2026-10-20')->assertOk()->assertJsonPath('meta.frozen', true);
    expect(collect($day->json('data'))->map(fn (array $item) => [$item['id'], $item['rank'], $item['votes_count']])->all())->toBe([[$first->id, 1, 2], [$second->id, 2, 1]]);
    $board = $this->getJson('/api/v1/leaderboards?period=all')->assertOk();
    expect(collect($board->json('data'))->pluck('id')->all())->toBe([$first->id, $second->id]);
    $week = $this->getJson('/api/v1/leaderboards?period=week')->assertOk();
    expect(collect($week->json('data'))->pluck('id')->all())->toBe([$first->id, $second->id]);
    $byTopic = $this->getJson("/api/v1/leaderboards?period=all&topic={$topic->slug}")->assertOk();
    expect(collect($byTopic->json('data'))->pluck('id')->all())->toBe([$first->id]);
    expect($this->getJson('/api/v1/today')->json('data'))->toBe([]);
});
