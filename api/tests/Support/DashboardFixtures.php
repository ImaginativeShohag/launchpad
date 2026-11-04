<?php

use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

const DASH_DAY = '2026-10-21';

/** The instant the dashboard tests live at: noon on the launch day. */
function dashNow(string $at = DASH_DAY.' 12:00:00'): DateTimeImmutable
{
    return new DateTimeImmutable($at.' UTC');
}

/** A live product launching on $day (UTC) with the given attributes; its maker is a fresh, available member. */
function dashProduct(string $day = DASH_DAY, array $attributes = []): Product
{
    return Product::factory()->live($day)->create($attributes);
}

/** One hourly bucket (raw upsert, as the vote path and the flush write it). $hour is a UTC `Y-m-d H:00:00` string. */
function dashBucket(Product $product, string $hour, int $votes = 0, int $views = 0): void
{
    DB::statement(
        'INSERT INTO product_hourly_stats (product_id, hour_start, votes, views) VALUES (?, ?, ?, ?) ON CONFLICT (product_id, hour_start) DO UPDATE SET votes = EXCLUDED.votes, views = EXCLUDED.views',
        [$product->id, $hour.'+00', $votes, $views],
    );
}

function dashReferrer(Product $product, string $day, string $source, int $visits): void
{
    DB::table('product_referrers_daily')->insert(['product_id' => $product->id, 'day' => $day, 'source' => $source, 'visits' => $visits]);
}

function dashSnapshot(Product $product, string $capturedAt, int $rank, float $score = 1.5): void
{
    DB::table('rank_snapshots')->insert(['product_id' => $product->id, 'captured_at' => $capturedAt.'+00', 'rank' => $rank, 'score' => $score]);
}

/** The frozen final rank of a launch day (what FreezeDay writes). */
function dashFreeze(Product $product, int $rank, ?string $day = null): void
{
    DB::table('daily_rankings')->insert([
        'launch_date' => $day ?? $product->launch_date->toDateString(), 'product_id' => $product->id, 'rank' => $rank,
        'votes_count' => $product->votes_count, 'score' => 1.0, 'frozen_at' => now('UTC')->format('Y-m-d H:i:sP'),
    ]);
}

/** @param  'me'|'staff'  $prefix */
function dashApi(string $prefix, string $productId, string $endpoint = '', array $query = []): TestResponse
{
    $path = "/api/v1/{$prefix}/products/{$productId}/dashboard".($endpoint === '' ? '' : "/{$endpoint}");

    return test()->getJson($path.($query === [] ? '' : '?'.http_build_query($query)));
}

/** A maker's own product, signed in as the maker for the rest of the test. */
function dashMakerProduct(string $day = DASH_DAY, array $attributes = []): Product
{
    $maker = signedInMember();

    return dashProduct($day, ['maker_id' => $maker->id, ...$attributes]);
}

/** @return list<string> the SQL of every statement the callback ran against the dashboard's stats tables */
function dashStatsQueries(Closure $callback): array
{
    $seen = [];
    DB::listen(function ($query) use (&$seen): void {
        if (preg_match('/\b(product_hourly_stats|product_referrers_daily|rank_snapshots|daily_rankings)\b/', $query->sql) === 1) {
            $seen[] = $query->sql;
        }
    });
    $callback();

    return $seen;
}

/** @return list<string> every statement the callback ran */
function dashAllQueries(Closure $callback): array
{
    $seen = [];
    DB::listen(function ($query) use (&$seen): void {
        $seen[] = $query->sql;
    });
    $callback();

    return $seen;
}

/** The stranger of a product: another member, signed in. */
function dashStranger(): User
{
    return signedInMember();
}

/** The frozen test clock as an immutable UTC instant. */
function positionNow(): CarbonImmutable
{
    return CarbonImmutable::now('UTC');
}

/** @return array<string, list<string>> "METHOD uri" (without api/v1/) => gathered middleware of every GET route */
function dashRouteMiddleware(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/') && in_array('GET', $route->methods(), true))
        ->mapWithKeys(fn ($route) => ['GET '.substr($route->uri(), 7) => $route->gatherMiddleware()])
        ->all();
}
