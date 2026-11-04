<?php

use App\Actions\Product\ScheduleLaunch;
use App\Actions\Product\WithdrawProduct;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Events\ProductScheduled;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-18 10:00:00 UTC')));

function launchDay(int $offset): string
{
    return Carbon::now('UTC')->addDays($offset)->toDateString();
}

function approvedOf(User $maker): Product
{
    return Product::factory()->approved()->create(['maker_id' => $maker->id]);
}

function pickDay(Product $product, mixed $date)
{
    return test()->putJson("/api/v1/products/{$product->id}/launch", ['launch_date' => $date]);
}

it('schedules an approved product for a day inside the window and fires the event', function () {
    // Given an approved product
    Event::fake([ProductScheduled::class]);
    $maker = signedInMember();
    $product = approvedOf($maker);

    // When the maker picks tomorrow
    $response = pickDay($product, launchDay(1));

    // Then it is scheduled for that UTC day
    $response->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.launch_date', launchDay(1));
    expect($product->fresh()->status)->toBe(ProductStatus::Scheduled);
    Event::assertDispatched(ProductScheduled::class, fn (ProductScheduled $e) => $e->productId === $product->id);
});

it('lets the maker change the day of a scheduled product, and ignores a repeat of the same day', function () {
    // Given a scheduled product
    Event::fake([ProductScheduled::class]);
    $maker = signedInMember();
    $product = Product::factory()->scheduled(launchDay(5))->create(['maker_id' => $maker->id]);

    // When the day changes, and then the same day is sent again
    $changed = pickDay($product, launchDay(9));
    $same = pickDay($product, launchDay(9));

    // Then the day moved, it stayed scheduled, and only the change fired an event
    $changed->assertOk()->assertJsonPath('data.launch_date', launchDay(9));
    $same->assertOk();
    expect($product->fresh()->status)->toBe(ProductStatus::Scheduled);
    Event::assertDispatchedTimes(ProductScheduled::class, 1);
});

it('enforces the window: tomorrow to launch.max_days_ahead days ahead, in UTC', function (int $offset, bool $allowed) {
    // Given an approved product and the default 60-day window
    $maker = signedInMember();
    $product = approvedOf($maker);

    // When a day is picked
    $response = pickDay($product, launchDay($offset));

    // Then only tomorrow to day 60 are accepted; the error names launch_date
    if ($allowed) {
        $response->assertOk();
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('launch_date');
        expect($product->fresh()->status)->toBe(ProductStatus::Approved)->and($product->fresh()->launch_date)->toBeNull();
    }
})->with([[-30, false], [-1, false], [0, false], [1, true], [30, true], [60, true], [61, false], [400, false]]);

it('reads the window from the setting', function () {
    // Given the window lowered to 10 days
    app(Settings::class)->set(SettingKey::LaunchMaxDaysAhead, 10, User::factory()->role(Role::Admin)->create());
    $maker = signedInMember();
    $product = approvedOf($maker);

    // When day 10 and day 11 are tried
    $ten = pickDay($product, launchDay(10));
    $eleven = pickDay(approvedOf($maker), launchDay(11));

    // Then 10 passes and 11 fails
    $ten->assertOk();
    $eleven->assertUnprocessable();
});

it('refuses malformed dates and wrong-shaped values with 422', function (mixed $value) {
    // Given an approved product
    $product = approvedOf(signedInMember());

    // When something that is not a real YYYY-MM-DD date arrives
    $response = pickDay($product, $value);

    // Then it is a 422 on launch_date, never a 500
    $response->assertUnprocessable()->assertJsonValidationErrors('launch_date');
})->with([
    'impossible date' => ['2026-02-31'], 'no zero padding' => ['2026-10-2'], 'inner space' => ['2026-11- 01'],
    'day first' => ['20-11-2026'], 'datetime' => ['2026-11-01T00:00:00Z'], 'text' => ['tomorrow'], 'empty' => [''],
    'null' => [null], 'array' => [['2026-11-01']], 'object' => [['a' => '2026-11-01']], 'integer' => [20261101],
    'true' => [true], 'false' => [false], 'sql' => ["2026-11-01'; drop table products;--"],
]);

it('counts only the maker\'s scheduled and live products of that day against the cap', function () {
    // Given a cap of 2 and a maker with two scheduled products on day 5
    $maker = signedInMember();
    Product::factory()->scheduled(launchDay(5))->count(2)->create(['maker_id' => $maker->id]);
    $third = approvedOf($maker);

    // When a third is scheduled for the same day, then for the next day
    $full = pickDay($third, launchDay(5));
    $free = pickDay($third, launchDay(6));

    // Then the same day is refused with a clear message and the next day works
    $full->assertUnprocessable()->assertJsonValidationErrors('launch_date');
    $free->assertOk();
});

it('lets other makers and taken-down products share the day', function () {
    // Given another maker with two scheduled products that day, and this maker's taken-down product on that day
    $maker = signedInMember();
    Product::factory()->scheduled(launchDay(5))->count(2)->create();
    Product::factory()->takenDown()->create(['maker_id' => $maker->id, 'launch_date' => launchDay(5)]);
    Product::factory()->takenDown()->create(['maker_id' => $maker->id, 'launch_date' => launchDay(5)]);

    // When this maker schedules two products that day
    $a = pickDay(approvedOf($maker), launchDay(5));
    $b = pickDay(approvedOf($maker), launchDay(5));

    // Then neither is blocked (taken-down slots are free again)
    $a->assertOk();
    $b->assertOk();
});

it('does not count the product being moved against itself and reads the cap from the setting', function () {
    // Given a cap raised to 3, two other scheduled products on day 5, and a scheduled product moving onto day 5
    app(Settings::class)->set(SettingKey::LaunchMaxPerMakerPerDay, 3, User::factory()->role(Role::Admin)->create());
    $maker = signedInMember();
    Product::factory()->scheduled(launchDay(5))->count(2)->create(['maker_id' => $maker->id]);
    $mine = Product::factory()->scheduled(launchDay(8))->create(['maker_id' => $maker->id]);

    // When it moves to day 5, then to day 5 again while a fourth product is also scheduled there
    $moved = pickDay($mine, launchDay(5));

    // Then the move is the third and passes
    $moved->assertOk();
    $fourth = approvedOf($maker);
    pickDay($fourth, launchDay(5))->assertUnprocessable();
});

it('takes the lock on the maker\'s whole product set before it counts, so parallel requests cannot exceed the cap', function () {
    // Given a maker with an approved product and a recorder of queries
    $maker = User::factory()->create();
    $product = approvedOf($maker);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    // When the action runs
    app(ScheduleLaunch::class)->handle($maker, $product, launchDay(3));

    // Then the first locking query is the maker's whole set in id order, and it precedes the cap count
    $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'for update'));
    $count = collect($queries)->search(fn ($sql) => str_contains($sql, 'count('));
    expect($queries[$lock])->toContain('"maker_id"')->toContain('order by "id" asc');
    expect($lock)->toBeLessThan($count);
});

it('refuses every status except approved and scheduled with 409', function (string $state) {
    // Given the maker's product in another status
    $product = Product::factory()->{$state}()->create(['maker_id' => signedInMember()->id]);

    // When a day is picked
    $response = pickDay($product, launchDay(3));

    // Then it is a 409
    $response->assertStatus(409)->assertJsonPath('code', 'invalid_status');
})->with(['complete', 'pending', 'live', 'rejected', 'changesRequested', 'takenDown']);

it('re-checks the status on the locked row', function () {
    // Given a stale approved copy of a product that was meanwhile withdrawn
    $maker = User::factory()->create();
    $product = approvedOf($maker);
    $stale = Product::query()->findOrFail($product->id);
    app(WithdrawProduct::class)->handle($maker, $product);

    // When the stale copy is scheduled
    $call = fn () => app(ScheduleLaunch::class)->handle($maker, $stale, launchDay(3));

    // Then the locked row says draft and the action refuses with the status error
    expect($call)->toThrow(ProductRuleViolation::class);
    expect($product->fresh()->status)->toBe(ProductStatus::Draft)->and($product->fresh()->launch_date)->toBeNull();
});

it('answers another member and staff with the uniform 404, before validating', function () {
    // Given someone else's approved product
    $foreign = Product::factory()->approved()->create();
    signedInMember();

    // When a member sends an invalid date to it, and an admin sends a valid one
    $asMember = pickDay($foreign, ['x']);
    signedInStaff(Role::Admin);
    $asAdmin = pickDay($foreign, launchDay(3));

    // Then both are the same 404 and nothing changed
    $asMember->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $asAdmin->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect($foreign->fresh()->launch_date)->toBeNull();
});

it('requires sign-in, a confirmed email and an active account', function () {
    // Given an approved product
    $product = Product::factory()->approved()->create();

    // When nobody, an unconfirmed member and a suspended member pick a day
    pickDay($product, launchDay(3))->assertUnauthorized();
    Sanctum::actingAs(User::factory()->unverified()->create());
    pickDay($product, launchDay(3))->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->suspended()->create());
    pickDay($product, launchDay(3))->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('counts a live product of that day against the cap', function () {
    // Given one scheduled and one live product of the maker on the same day
    $maker = signedInMember();
    Product::factory()->scheduled(launchDay(5))->create(['maker_id' => $maker->id]);
    Product::factory()->live(launchDay(5))->create(['maker_id' => $maker->id]);

    // When a third is scheduled for that day
    $response = pickDay(approvedOf($maker), launchDay(5));

    // Then the cap of 2 is reached
    $response->assertUnprocessable()->assertJsonValidationErrors('launch_date');
});

it('names the window when day 11 is refused under a 10-day setting', function () {
    // Given a 10-day window
    app(Settings::class)->set(SettingKey::LaunchMaxDaysAhead, 10, User::factory()->role(Role::Admin)->create());

    // When day 11 is picked
    $response = pickDay(approvedOf(signedInMember()), launchDay(11));

    // Then the message is about the window
    $response->assertUnprocessable()->assertJsonValidationErrors('launch_date');
    expect($response->json('errors.launch_date.0'))->toContain('10 days ahead');
});

it('uses the UTC calendar day whatever the application timezone is', function () {
    // Given 20:00 UTC (already tomorrow in Dhaka) and an application timezone of Asia/Dhaka
    config(['app.timezone' => 'Asia/Dhaka']);
    $this->travelTo(new DateTimeImmutable('2026-10-18 20:00:00 UTC'));
    $maker = signedInMember();

    // When the maker picks 2026-10-19 (tomorrow in UTC), and then 2026-10-18 (today in UTC)
    $tomorrow = pickDay(approvedOf($maker), '2026-10-19');
    $today = pickDay(approvedOf($maker), '2026-10-18');

    // Then tomorrow is accepted and today is refused
    $tomorrow->assertOk();
    $today->assertUnprocessable();
});

it('moves the window at midnight UTC', function () {
    // Given one second before midnight UTC
    $this->travelTo(new DateTimeImmutable('2026-10-18 23:59:59 UTC'));
    $maker = signedInMember();
    $product = approvedOf($maker);

    // When 2026-10-19 is picked just before and 2026-10-19 again just after midnight
    $before = pickDay($product, '2026-10-19');
    $this->travelTo(new DateTimeImmutable('2026-10-19 00:00:00 UTC'));
    $after = pickDay(approvedOf($maker), '2026-10-19');

    // Then before midnight it is tomorrow; after midnight it is today and refused
    $before->assertOk();
    $after->assertUnprocessable();
});

it('writes an audit row without personal data and fires no event on refusals', function () {
    // Given an approved product, with the real listener registered
    $fired = 0;
    Event::listen(ProductScheduled::class, function () use (&$fired) {
        $fired++;
    });
    $maker = signedInMember();
    $product = approvedOf($maker);

    // When a bad window, then a valid day, then a wrong-status product are tried
    pickDay($product, launchDay(99))->assertUnprocessable();
    expect($fired)->toBe(0);
    pickDay($product, launchDay(4))->assertOk();
    pickDay(Product::factory()->live()->create(['maker_id' => $maker->id]), launchDay(4))->assertStatus(409);

    // Then the listener ran exactly once and one audit row holds ids and the day only
    expect($fired)->toBe(1);
    $audit = DB::table('audit_logs')->where('action', 'product.scheduled')->get();
    expect($audit)->toHaveCount(1);
    expect(json_decode($audit[0]->meta, true))->toBe(['from_status' => 'approved', 'launch_date' => launchDay(4)]);
});

it('returns the locked row on a same-day repeat even when the bound copy is stale', function () {
    // Given a scheduled product whose day another request has already moved to day 9
    $maker = User::factory()->create();
    $product = Product::factory()->scheduled(launchDay(5))->create(['maker_id' => $maker->id]);
    $stale = Product::query()->findOrFail($product->id);
    DB::table('products')->where('id', $product->id)->update(['launch_date' => launchDay(9)]);

    // When the stale copy asks for day 9 as well
    $result = app(ScheduleLaunch::class)->handle($maker, $stale, launchDay(9));

    // Then the answer shows day 9
    expect($result->launch_date->toDateString())->toBe(launchDay(9));
});

it('answers an unknown product id with the uniform 404', function () {
    // Given a signed-in member
    signedInMember();

    // When a day is picked for a product that does not exist
    $response = test()->putJson('/api/v1/products/01JZZZZZZZZZZZZZZZZZZZZZZZ/launch', ['launch_date' => launchDay(3)]);

    // Then it is a 404
    $response->assertNotFound();
});

it('carries a stable machine code next to the 422 launch_date error for each refusal', function (int $offset, string $code) {
    // Given an approved product
    $product = approvedOf(signedInMember());

    // When a day is picked that is today, in the past, or beyond the window
    $response = pickDay($product, launchDay($offset));

    // Then the usual 422 on launch_date also names the cause
    $response->assertUnprocessable()->assertJsonValidationErrors('launch_date')->assertJsonPath('code', $code);
    expect($response->json('message'))->toBe($response->json('errors.launch_date.0'));
})->with([[0, 'launch_date_today'], [-3, 'launch_date_out_of_window'], [61, 'launch_date_out_of_window']]);

it('names the cap reached and the impossible date with their own codes', function () {
    // Given a maker at the cap on day 5
    $maker = signedInMember();
    Product::factory()->scheduled(launchDay(5))->count(2)->create(['maker_id' => $maker->id]);
    $third = approvedOf($maker);

    // When the same day is picked for a third, and an impossible date goes straight to the action
    $full = pickDay($third, launchDay(5));
    $caught = null;
    try {
        app(ScheduleLaunch::class)->handle($maker, $third, '2026-02-31');
    } catch (ValidationException $e) {
        $caught = $e;
    }

    // Then the cap has its code, and the action's date check has its own
    $full->assertUnprocessable()->assertJsonPath('code', 'launch_cap_reached');
    expect($caught?->response?->getData(true)['code'] ?? null)->toBe('launch_date_invalid');
});
