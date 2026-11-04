<?php

use App\Actions\Views\FlushReport;
use App\Actions\Views\FlushViews;
use App\Models\Product;
use App\Support\ViewCounters;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;

const VIEW_NOON = '2026-10-21 12:00:00';

/**
 * Empties the view-counting Redis database. Refuses to run unless that database is the one phpunit.xml pins (15), so a
 * missing pin can never wipe the development data.
 */
function viewRedisReset(): void
{
    $database = (string) config('database.redis.views.database');
    if ($database !== '15') {
        throw new RuntimeException("Refusing to flush Redis database {$database}: the test database must be 15.");
    }

    // The configured number is not proof: ask the live connection which database it is really on (a REDIS_URL or a
    // cached connection could differ) before anything is flushed.
    $connection = Redis::connection((string) config('launchpad.views.redis_connection'));
    $live = $connection->client()->getDbNum();
    if ($live !== 15) {
        throw new RuntimeException("Refusing to flush: the live Redis connection is on database {$live}, must be 15.");
    }

    $connection->flushdb();
}

/** @return array<string, string> the field => count map of views:pending, read through the counters class */
function viewPendingHash(): array
{
    return app(ViewCounters::class)->pending();
}

/** A live product launching today (UTC) with an available maker. */
function viewVisibleProduct(array $attrs = []): Product
{
    return Product::factory()->live(Carbon::now('UTC')->toDateString())->create($attrs);
}

/** @return array<string, string> the header of a device (a valid 16 to 64 character id) */
function viewDevice(string $id): array
{
    return ['X-Device-Id' => str_pad($id, 16, '0', STR_PAD_LEFT)];
}

/** A JSON beacon with a human user agent unless the headers say otherwise. */
function viewBeacon(string $productId, array $payload = [], array $headers = []): TestResponse
{
    return test()->postJson("/api/v1/products/{$productId}/view", $payload, array_merge(
        ['User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'],
        $headers,
    ));
}

const FLUSH_DAY = '2026-10-21';

function flushHour(int $hour): int
{
    return CarbonImmutable::parse(FLUSH_DAY, 'UTC')->setTime($hour, 0)->getTimestamp();
}

/** Writes raw fields into views:pending exactly as the beacon would (field => count). */
function flushPending(array $fields): void
{
    $redis = Redis::connection(config('launchpad.views.redis_connection'));
    foreach ($fields as $field => $count) {
        $redis->hset('views:pending', (string) $field, $count);
    }
}

/** The product's hourly rows as epoch => [votes, views]. */
function flushBuckets(Product|string $product): array
{
    $id = $product instanceof Product ? $product->id : $product;

    return DB::table('product_hourly_stats')->where('product_id', $id)->orderBy('hour_start')->get()
        ->mapWithKeys(fn ($r) => [CarbonImmutable::parse($r->hour_start)->getTimestamp() => [$r->votes, $r->views]])->all();
}

/** The product's referrer rows as "day|source" => visits. */
function flushReferrers(Product|string $product): array
{
    $id = $product instanceof Product ? $product->id : $product;

    return DB::table('product_referrers_daily')->where('product_id', $id)->orderBy('day')->orderBy('source')->get()
        ->mapWithKeys(fn ($r) => [$r->day.'|'.$r->source => $r->visits])->all();
}

function flushRun(): FlushReport
{
    return app(FlushViews::class)->handle();
}

/** @return list<string> every key in the views database except the batch sequence counter */
function flushRedisKeys(): array
{
    // The sequence counter `views:seq` is meant to stay (batch ids never repeat).
    return array_values(array_filter(
        Redis::connection(config('launchpad.views.redis_connection'))->keys('*'),
        fn (string $key) => ! str_ends_with($key, 'views:seq'),
    ));
}

/** Stores N already known sources for a product and day (visits 1 each). */
function flushStoreSources(Product $product, array $sources, string $day = FLUSH_DAY): void
{
    DB::table('product_referrers_daily')->insert(array_map(fn ($s) => ['product_id' => $product->id, 'day' => $day, 'source' => $s, 'visits' => 1], $sources));
}

/** Pending fields for a source map: an hourly field with the total and one referrer field per source. */
function flushPendingSources(Product $product, array $visitsBySource, string $day = FLUSH_DAY): void
{
    $fields = ["p|{$product->id}|".CarbonImmutable::parse($day, 'UTC')->setTime(10, 0)->getTimestamp() => array_sum($visitsBySource)];
    foreach ($visitsBySource as $source => $visits) {
        $fields["r|{$product->id}|{$day}|{$source}"] = $visits;
    }
    flushPending($fields);
}

function viewCounters(): ViewCounters
{
    return app(ViewCounters::class);
}

function viewNow(): CarbonImmutable
{
    return CarbonImmutable::now('UTC');
}

/** One record() call with readable defaults. */
function viewRecord(string $product = 'P1', string $device = 'd1', string $ip = 'ip1', string $source = 'direct', ?CarbonImmutable $at = null): int
{
    return viewCounters()->record($product, $device, $ip, $at ?? viewNow(), $source);
}

function viewRedis()
{
    return Redis::connection(config('launchpad.views.redis_connection'));
}

/** @return array<string, int> the pending hash, referrer fields only */
function viewRefFields(): array
{
    return array_map('intval', array_filter(viewPendingHash(), fn ($v, $k) => str_starts_with($k, 'r|'), ARRAY_FILTER_USE_BOTH));
}

/** @return array<string, int> the pending hash, hour fields only */
function viewHourFields(): array
{
    return array_map('intval', array_filter(viewPendingHash(), fn ($v, $k) => str_starts_with($k, 'p|'), ARRAY_FILTER_USE_BOTH));
}

/** A beacon with a hand-made body and content type (the helper cannot send a JSON scalar or a text body). */
function viewRaw(string $productId, string $content, string $contentType = 'application/json', string $query = ''): TestResponse
{
    return test()->call('POST', "/api/v1/products/{$productId}/view{$query}", [], [], [], [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0 Safari/537.36',
        'HTTP_X_DEVICE_ID' => 'device-input-0001',
    ], $content);
}

/** @return list<string> the referrer sources that were counted */
function viewCountedSources(): array
{
    return collect(array_keys(viewPendingHash()))->filter(fn ($k) => str_starts_with($k, 'r|'))->map(fn ($k) => explode('|', $k)[3])->values()->all();
}

/** Asks a CSRF middleware whether a path is excluded (the test harness skips CSRF, so a request cannot show a 419). */
function viewCsrfExcepts(string $class, string $uri, string $method = 'POST'): bool
{
    $probe = match ($class) {
        ValidateCsrfToken::class => new class(app(), app('encrypter')) extends ValidateCsrfToken
        {
            public function excepted(Request $request): bool
            {
                return $this->inExceptArray($request);
            }
        },
        PreventRequestForgery::class => new class(app(), app('encrypter')) extends PreventRequestForgery
        {
            public function excepted(Request $request): bool
            {
                return $this->inExceptArray($request);
            }
        },
    };

    expect($probe)->toBeInstanceOf($class);

    return $probe->excepted(Request::create($uri, $method));
}
