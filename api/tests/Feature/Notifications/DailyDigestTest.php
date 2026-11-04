<?php

use App\Enums\SettingKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/DigestFixtures.php';
require_once __DIR__.'/../../Support/RetentionFixtures.php';

function digestAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

beforeEach(function () {
    $this->travelTo(new DateTimeImmutable('2026-10-21 07:00:00 UTC'));
    $this->member = User::factory()->create();
});

it('is due from the send time on the send day, for the day that just ended', function (string $now, bool $due) {
    // Given a frozen day 2026-10-20 with one visible launch
    $this->travelTo(new DateTimeImmutable($now.' UTC'));
    digestFreeze('2026-10-20', [digestProduct('2026-10-20')]);

    // When the digest job runs at the given instant
    $result = digestAction()->handle(digestAt($now))['daily_top5'];

    // Then it is due from 07:00:00 and its period is yesterday
    expect($result === null)->toBe(! $due);
    // (every verified member is a recipient: the beforeEach member and the product's maker)
    expect(DB::table('email_messages')->where('type', 'daily_top5')->count())->toBe($due ? 2 : 0);
    if ($due) {
        expect($result->period)->toBe(CarbonImmutable::parse($now, 'UTC')->subDay()->toDateString())->and($result->state)->toBe('completed')->and($result->enqueued)->toBe(2);
    }
})->with([
    'a second before the send time' => ['2026-10-21 06:59:59', false],
    'at the send time' => ['2026-10-21 07:00:00', true],
    'late in the send day' => ['2026-10-21 23:59:59', true],
]);

it('abandons a run older than 18 hours at the next period and reports it as failed, leaving the new period to its own run', function () {
    // Given an unfinished run for the 20th (chunk of 1, stopped by a crash after the first chunk) and two members
    Exceptions::fake();
    digestUsers(2);
    digestFreeze('2026-10-20', [digestProduct('2026-10-20')]);
    digestAction(1, function () {
        throw new RuntimeException('crash');
    })->handle(digestAt('2026-10-21 07:00:00'));
    $this->travelTo(new DateTimeImmutable('2026-10-22 07:00:00 UTC'));

    // When the job runs 24 hours later on the 22nd
    $action = digestAction();
    $result = $action->handle(digestAt('2026-10-22 07:00:00'))['daily_top5'];

    // Then the 20th was abandoned (closed, counted as a failure) and the 21st (not frozen yet) has no run
    expect($action->failed)->toBe(1)->and($action->failedKinds)->toBe(['daily_top5']);
    expect($result->period)->toBe('2026-10-20')->and($result->state)->toBe('abandoned')->and($result->completed)->toBeFalse();
    expect(digestRun('daily_top5', '2026-10-20')['completed_at'])->not->toBeNull()->and(digestRun('daily_top5', '2026-10-21'))->toBeNull();
});

it('reads the send time live and falls back to 07:00 with a warning for a malformed value', function () {
    // Given a frozen day and the send time moved to 06:30
    digestFreeze('2026-10-20', [digestProduct('2026-10-20')]);
    retentionStoreRaw(SettingKey::DigestDailySendAtUtc, '06:30');

    // When it runs at 06:45
    $early = digestAction()->handle(digestAt('2026-10-21 06:45:00'))['daily_top5'];

    // Then the new time is honoured
    expect($early?->state)->toBe('completed');

    // And a malformed stored value means 07:00, with a warning naming only the key
    DB::table('digest_runs')->delete();
    Log::spy();
    retentionStoreRaw(SettingKey::DigestDailySendAtUtc, '7am');
    $before = digestAction()->handle(digestAt('2026-10-21 06:59:00'))['daily_top5'];
    $after = digestAction()->handle(digestAt('2026-10-21 07:00:00'))['daily_top5'];
    expect($before)->toBeNull()->and($after?->state)->toBe('completed');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => $c === ['key' => 'digest.daily_send_at_utc'])->twice();
});

it('waits on an unfrozen day, writes no run, and closes an empty day with nothing at 23:00', function () {
    // Given a member and no frozen ranking for the 20th
    digestUsers(1);

    // When the job runs in the morning
    $morning = digestAction()->handle(digestAt('2026-10-21 07:00:00'))['daily_top5'];

    // Then it waits: no run row, no outbox row
    expect($morning->state)->toBe('waiting')->and($morning->completed)->toBeFalse()->and(DB::table('digest_runs')->count())->toBe(0)->and(DB::table('email_messages')->count())->toBe(0);

    // And just before 23:00 it still waits, while at 23:10 the run is closed with nothing to send
    expect(digestAction()->handle(digestAt('2026-10-21 22:59:59'))['daily_top5']->state)->toBe('waiting');
    $late = digestAction()->handle(digestAt('2026-10-21 23:10:00'))['daily_top5'];
    expect($late->state)->toBe('completed')->and($late->enqueued)->toBe(0)->and($late->completed)->toBeTrue();
    $run = digestRun('daily_top5', '2026-10-20');
    expect($run['completed_at'])->not->toBeNull()->and($run['enqueued'])->toBe(0)->and(json_decode($run['product_ids']))->toBe([]);
    expect(DB::table('email_messages')->count())->toBe(0);
});

it('takes the top five visible products of the frozen day in rank order', function () {
    // Given a frozen day with eight launches: rank 2 taken down, rank 3 of a deleted maker, rank 4 soft-deleted
    digestUsers(1);
    $products = collect(range(1, 8))->map(fn () => digestProduct('2026-10-20'))->all();
    digestFreeze('2026-10-20', $products);
    digestTakeDown($products[1]);
    DB::table('users')->where('id', $products[2]->maker_id)->update(['deleted_at' => now()]);
    DB::table('products')->where('id', $products[3]->id)->update(['deleted_at' => now()]);

    // When the digest is queued
    digestAction()->handle(digestAt('2026-10-21 07:00:00'));

    // Then the list is ranks 1, 5, 6, 7, 8 in rank order: hidden ones are filtered BEFORE taking five
    $expected = [$products[0]->id, $products[4]->id, $products[5]->id, $products[6]->id, $products[7]->id];
    expect(json_decode(digestRun('daily_top5', '2026-10-20')['product_ids']))->toBe($expected);
    $mail = digestMail('daily_top5');
    expect($mail)->not->toBeEmpty();
    expect(json_decode($mail[0]['payload'], true))->toBe(['date' => '2026-10-20', 'product_ids' => $expected]);
});

it('orders by the frozen rank even when ranks have gaps, and lists fewer than five when fewer exist', function () {
    // Given three launches frozen at ranks 2, 5 and 9 (ids in the opposite order)
    digestUsers(1);
    $a = digestProduct('2026-10-20');
    $b = digestProduct('2026-10-20');
    $c = digestProduct('2026-10-20');
    digestFreeze('2026-10-20', [$c, $a, $b], [2, 5, 9]);

    // When the digest is queued
    digestAction()->handle(digestAt('2026-10-21 07:00:00'));

    // Then the order is by rank and only three products are listed
    expect(json_decode(digestRun('daily_top5', '2026-10-20')['product_ids']))->toBe([$c->id, $a->id, $b->id]);
});

it('completes with nothing when rows exist but none is visible', function () {
    // Given a frozen day whose only launch was taken down
    digestUsers(2);
    $product = digestProduct('2026-10-20');
    digestFreeze('2026-10-20', [$product]);
    digestTakeDown($product);

    // When the digest is queued in the morning
    $result = digestAction()->handle(digestAt('2026-10-21 07:00:00'))['daily_top5'];

    // Then the run is complete at once with nothing enqueued
    expect($result->state)->toBe('completed')->and($result->enqueued)->toBe(0)->and(DB::table('email_messages')->count())->toBe(0);
    expect(digestRun('daily_top5', '2026-10-20')['completed_at'])->not->toBeNull();
});

it('chooses the list once: later chunks keep it after the day changes under the run', function () {
    // Given a frozen day with three launches, members, and a chunk of one that takes a product down after chunk one
    digestUsers(3);
    $products = collect(range(1, 3))->map(fn () => digestProduct('2026-10-20'))->all();
    digestFreeze('2026-10-20', $products);
    $fired = false;
    $action = digestAction(1, function () use ($products, &$fired) {
        if (! $fired) {
            $fired = true;
            digestTakeDown($products[0]);
        }
    });

    // When the run is queued
    $action->handle(digestAt('2026-10-21 07:00:00'));

    // Then every member's payload carries the same stored list, taken-down product included (the mail drops it when rendering)
    $lists = collect(digestMail('daily_top5'))->map(fn ($row) => json_decode($row['payload'], true)['product_ids'])->unique()->values()->all();
    expect($lists)->toBe([collect($products)->pluck('id')->all()]);
    expect(DB::table('email_messages')->where('type', 'daily_top5')->count())->toBe(DB::table('users')->count());
});

it('writes the documented outbox row and no in-site notification', function () {
    // Given a frozen day and one member
    $ids = digestUsers(1);
    $product = digestProduct('2026-10-20');
    digestFreeze('2026-10-20', [$product]);
    $this->travelTo(new DateTimeImmutable('2026-10-21 07:00:00.250000 UTC'));

    // When the digest is queued
    digestAction()->handle(digestAt('2026-10-21 07:00:00'));

    // Then the row is queued, due now, keyed by day and member, with the member's address and ids only
    $row = collect(digestMail('daily_top5'))->firstWhere('user_id', $ids[0]);
    expect($row['status'])->toBe('queued')->and($row['idempotency_key'])->toBe('daily_top5:2026-10-20:'.$ids[0])->and($row['to_email'])->toBe(DB::table('users')->where('id', $ids[0])->value('email'))
        ->and((int) $row['attempts'])->toBe(0)->and($row['last_error'])->toBeNull()->and(strlen($row['id']))->toBe(26)
        ->and(json_decode($row['payload'], true))->toBe(['date' => '2026-10-20', 'product_ids' => [$product->id]]);
    expect(strtotime($row['next_attempt_at']))->toBe(strtotime('2026-10-21 07:00:00 UTC'));
    expect(DB::table('notifications')->count())->toBe(0);
});
