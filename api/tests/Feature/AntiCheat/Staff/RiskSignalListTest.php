<?php

use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Models\RiskSignal;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    antiCheatFreeze();
    signedInStaff(Role::Reviewer);
});

/** An open signal with an exact weight and detection instant. */
function antiCheatListSignal(int $weight, CarbonImmutable|string $at, array $attributes = []): RiskSignal
{
    return RiskSignal::factory()->kind(RiskSignalKind::SharedDevice, $weight)->detectedAt(CarbonImmutable::parse((string) $at, 'UTC'))->create($attributes);
}

it('lists the open queue by default, heaviest first', function () {
    // Given two open signals and a reviewed one
    $light = antiCheatListSignal(15, antiCheatNow());
    $heavy = antiCheatListSignal(40, antiCheatNow()->subHour());
    $reviewed = RiskSignal::factory()->reviewed(RiskOutcome::Dismissed)->create(['weight' => 90]);

    // When the list is read without a status
    $response = antiCheatGet('risk-signals');

    // Then only the open ones come back, heaviest first
    $response->assertOk();
    expect(antiCheatIds($response))->toBe([$heavy->id, $light->id])->and(antiCheatIds($response))->not->toContain($reviewed->id);
});

it('breaks ties by detection time and then by id, and walks every row once for open, reviewed and all', function () {
    // Given seven open signals with equal weights and equal times in groups, and three reviewed ones
    $rows = [];
    foreach ([[40, '12:00:00'], [40, '12:00:00'], [40, '12:00:00'], [40, '11:00:00'], [25, '12:00:00'], [25, '12:00:00'], [10, '09:00:00']] as [$weight, $time]) {
        $rows[] = antiCheatListSignal($weight, '2026-10-21 '.$time);
    }
    $reviewed = array_map(fn (int $i) => RiskSignal::factory()->reviewed(RiskOutcome::Confirmed)->detectedAt(antiCheatNow()->subDays($i))->create([
        'reviewed_at' => antiCheatNow()->subMinutes($i % 2),
    ]), [1, 2, 3]);

    // When each status is walked with two rows per page
    $open = antiCheatWalk('risk-signals', ['status' => 'open']);
    $closed = antiCheatWalk('risk-signals', ['status' => 'reviewed']);
    $all = antiCheatWalk('risk-signals', ['status' => 'all']);

    // Then every row appears exactly once, in the documented order (ids decide ties, descending)
    $sortedOpen = collect($rows)->sort(fn ($a, $b) => [$b->weight, $b->detected_at->timestamp, $b->id] <=> [$a->weight, $a->detected_at->timestamp, $a->id])->pluck('id')->values()->all();
    expect($open)->toBe($sortedOpen);
    $sortedClosed = collect($reviewed)->sort(fn ($a, $b) => [$b->reviewed_at->timestamp, $b->id] <=> [$a->reviewed_at->timestamp, $a->id])->pluck('id')->values()->all();
    expect($closed)->toBe($sortedClosed);
    $sortedAll = collect([...$rows, ...$reviewed])->sort(fn ($a, $b) => [$b->detected_at->timestamp, $b->id] <=> [$a->detected_at->timestamp, $a->id])->pluck('id')->values()->all();
    expect($all)->toBe($sortedAll)->and(count($all))->toBe(10);
});

it('filters by kind, member, product and minimum weight, alone and combined', function () {
    // Given signals of different kinds, members and products
    $member = User::factory()->create();
    $product = antiCheatLiveProduct();
    $a = antiCheatListSignal(40, antiCheatNow(), ['user_id' => $member->id, 'product_id' => $product->id]);
    $b = RiskSignal::factory()->kind(RiskSignalKind::FreshAccount, 15)->create(['user_id' => $member->id, 'product_id' => null, 'detected_at' => antiCheatNow()]);
    $c = antiCheatListSignal(25, antiCheatNow());

    // When each filter is applied
    // Then the matching rows come back
    expect(antiCheatIds(antiCheatGet('risk-signals', ['kind' => 'fresh_account'])))->toBe([$b->id]);
    expect(antiCheatIds(antiCheatGet('risk-signals', ['user' => $member->id])))->toBe([$a->id, $b->id]);
    expect(antiCheatIds(antiCheatGet('risk-signals', ['product' => $product->id])))->toBe([$a->id]);
    expect(antiCheatIds(antiCheatGet('risk-signals', ['min_weight' => 25])))->toBe([$a->id, $c->id]);
    expect(antiCheatIds(antiCheatGet('risk-signals', ['min_weight' => 26])))->toBe([$a->id]);
    expect(antiCheatIds(antiCheatGet('risk-signals', ['user' => $member->id, 'kind' => 'shared_device', 'min_weight' => 40])))->toBe([$a->id]);
    expect(antiCheatIds(antiCheatGet('risk-signals', ['min_weight' => 0])))->toHaveCount(3);
});

it('applies from and to as whole UTC days, both ends inclusive to the second', function () {
    // Given signals just before, at the start, at the end, and just after the day 2026-10-20
    $before = antiCheatListSignal(10, '2026-10-19 23:59:59');
    $start = antiCheatListSignal(10, '2026-10-20 00:00:00');
    $end = antiCheatListSignal(10, '2026-10-20 23:59:59');
    $after = antiCheatListSignal(10, '2026-10-21 00:00:00');

    // When the day is asked for, alone and as from/to
    // Then exactly the day's rows come back, and a range extends both ways
    $day = antiCheatIds(antiCheatGet('risk-signals', ['from' => '2026-10-20', 'to' => '2026-10-20']));
    expect($day)->toEqualCanonicalizing([$start->id, $end->id]);
    expect(antiCheatIds(antiCheatGet('risk-signals', ['from' => '2026-10-20'])))->toEqualCanonicalizing([$start->id, $end->id, $after->id]);
    expect(antiCheatIds(antiCheatGet('risk-signals', ['to' => '2026-10-20'])))->toEqualCanonicalizing([$before->id, $start->id, $end->id]);
});

it('answers 422 for a from after the to, and for every malformed parameter', function (string $query) {
    // Given a reviewer and one signal
    antiCheatListSignal(40, antiCheatNow());

    // When the list is read with the bad parameter
    // Then it is a 422, never a 500
    antiCheatGet('risk-signals', $query)->assertStatus(422);
})->with([
    'from after to' => 'from=2026-10-21&to=2026-10-20',
    'status unknown' => 'status=pending',
    'status array' => 'status[]=open',
    'status object' => 'status[a]=open',
    'status null' => 'status=',
    'kind unknown' => 'kind=nope',
    'kind array' => 'kind[]=shared_device',
    'user not a ulid' => 'user=abc',
    'user array' => 'user[]=01ARZ3NDEKTSV4RRFFQ69G5FAV',
    'user nul' => 'user=01ARZ3NDEKTSV4RRFFQ69G5F%00',
    'product not a ulid' => 'product=1',
    'product object' => 'product[a]=b',
    'min_weight array' => 'min_weight[]=1',
    'min_weight object' => 'min_weight[a]=1',
    'min_weight true' => 'min_weight=true',
    'min_weight negative' => 'min_weight=-1',
    'min_weight over' => 'min_weight=101',
    'min_weight float' => 'min_weight=1.5',
    'min_weight garbage' => 'min_weight=abc',
    'min_weight null' => 'min_weight=',
    'from impossible' => 'from=2026-02-31',
    'from format' => 'from=21-10-2026',
    'from year zero' => 'from=0000-01-01',
    'from array' => 'from[]=2026-10-21',
    'to garbage' => 'to=tomorrow',
    'to null' => 'to=',
    'per_page zero' => 'per_page=0',
    'per_page over' => 'per_page=101',
    'per_page array' => 'per_page[]=5',
    'per_page garbage' => 'per_page=x',
]);

it('rejects a tampered cursor with 422 and treats an empty cursor as the first page', function (string $status, string $cursor) {
    // Given a reviewer and signals
    antiCheatListSignal(40, antiCheatNow());
    $date = '2026-10-21T12:00:00.000000Z';
    $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $cursor = str_replace(['{date}', '{ulid}'], [$date, $ulid], $cursor);

    // When the list is read with the cursor
    // Then it is a 422
    antiCheatGet('risk-signals', ['status' => $status, 'cursor' => str_starts_with($cursor, 'raw:') ? substr($cursor, 4) : antiCheatCursor(json_decode($cursor, true) ?? $cursor)])->assertStatus(422);
})->with([
    'not base64' => ['open', 'raw:!!!'],
    'json null' => ['open', 'raw:'.'bnVsbA'],
    'no pointer' => ['open', '{"weight":40,"detected_at":"{date}","id":"{ulid}"}'],
    'wrong keys' => ['open', '{"x":1,"_pointsToNextItems":true}'],
    'open cursor on all' => ['all', '{"weight":40,"detected_at":"{date}","id":"{ulid}","_pointsToNextItems":true}'],
    'reviewed cursor on open' => ['open', '{"reviewed_at":"{date}","id":"{ulid}","_pointsToNextItems":true}'],
    'weight as string' => ['open', '{"weight":"40","detected_at":"{date}","id":"{ulid}","_pointsToNextItems":true}'],
    'impossible date' => ['open', '{"weight":40,"detected_at":"2026-02-31T00:00:00.000000Z","id":"{ulid}","_pointsToNextItems":true}'],
    'not a ulid' => ['open', '{"weight":40,"detected_at":"{date}","id":"nope","_pointsToNextItems":true}'],
    'negative weight' => ['open', '{"weight":-1,"detected_at":"{date}","id":"{ulid}","_pointsToNextItems":true}'],
]);

it('treats an empty cursor as page one and carries the filters in the next link', function () {
    // Given three open signals
    foreach ([40, 30, 20] as $w) {
        antiCheatListSignal($w, antiCheatNow());
    }

    // When the first page is read with an empty cursor and a filter
    $response = antiCheatGet('risk-signals', 'cursor=&per_page=2&min_weight=10&kind=shared_device');

    // Then it is page one, and the next link keeps the filters and the page size
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    $next = $response->json('links.next') ?? $response->json('meta.next_cursor');
    expect($response->json('links.next'))->toContain('per_page=2')->toContain('min_weight=10')->toContain('kind=shared_device')->toContain('status=open');
    expect($next)->not->toBeNull();
});

it('accepts the largest page size', function () {
    // Given one signal
    antiCheatListSignal(40, antiCheatNow());

    // When per_page is 100
    // Then it is served
    antiCheatGet('risk-signals', ['per_page' => 100])->assertOk();
});

it('pins the keys of an item and shows a deleted member anonymised without an e-mail', function () {
    // Given a signal about a deleted member (anonymised row) with a reviewer, and one about a live member on a product
    $deleted = User::factory()->create(['handle' => 'deleted-abc', 'name' => 'Deleted member', 'deleted_at' => antiCheatNow()->subDay(), 'email' => 'gone-1@deleted.invalid']);
    $signal = RiskSignal::factory()->reviewed(RiskOutcome::Confirmed)->create(['user_id' => $deleted->id, 'review_note' => 'seen']);

    // When the reviewed list is read
    $response = antiCheatGet('risk-signals', ['status' => 'reviewed'])->assertOk();

    // Then the item has exactly the pinned keys and the anonymised member, and the body carries no e-mail
    $item = $response->json('data.0');
    expect(array_keys($item))->toBe(['id', 'kind', 'status', 'weight', 'detected_at', 'reviewed_at', 'reviewer', 'review_note', 'outcome', 'member', 'product', 'observed', 'threshold']);
    expect($item['member'])->toBe(['id' => $deleted->id, 'handle' => 'deleted-abc', 'name' => 'Deleted member', 'risk_score' => 0, 'suspended' => false, 'deleted' => true]);
    expect($item['status'])->toBe('confirmed')->and($item['reviewer'])->toHaveKeys(['id', 'handle'])->and($item['observed'])->toBe(3)->and($item['threshold'])->toBe(3);
    expect($response->getContent())->not->toContain('@')->not->toContain('gone-1');
});

it('shows a product-level signal without a member', function () {
    // Given a product-only burst signal
    RiskSignal::factory()->forProductOnly()->kind(RiskSignalKind::VoteBurst, 0)->create();

    // When the list is read
    // Then the member is null and the product is present
    $item = antiCheatGet('risk-signals')->assertOk()->json('data.0');
    expect($item['member'])->toBeNull()->and($item['product'])->toHaveKeys(['id', 'slug', 'name']);
});
