<?php

use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoteSignals;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('exports');
    $this->travelTo(dashNow());
});

/** @return list<string> every key (at any depth) of a decoded JSON value */
function noLeakKeys(mixed $value): array
{
    $keys = [];
    if (is_array($value)) {
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            array_push($keys, ...noLeakKeys($child));
        }
    }

    return $keys;
}

const NO_LEAK_KEYS = ['views', 'visits', 'source', 'sources', 'conversion', 'referrers', 'export', 'exports', 'file_path', 'file_size', 'row_counts', 'download_url'];

it('shows no dashboard or export key in any public body, for a guest and for a signed-in non-maker', function () {
    // Given a live product launching today with views, referrers, buckets, snapshots and a ready export
    $maker = User::factory()->create(['handle' => 'leakmaker']);
    $product = dashProduct(DASH_DAY, ['maker_id' => $maker->id, 'votes_count' => 2, 'name' => 'Leak Probe', 'tagline' => 'Probe']);
    dashBucket($product, '2026-10-21 09:00:00', 2, 91);
    dashReferrer($product, DASH_DAY, 'x', 91);
    dashSnapshot($product, '2026-10-21 09:00:00', 1);
    $export = exportFor($product, 'ready');
    exportStoreFile($export);
    $reads = [
        "/api/v1/products/{$product->slug}", '/api/v1/today', '/api/v1/days/2026-10-21', '/api/v1/leaderboards?period=week',
        '/api/v1/users/leakmaker/products', '/api/v1/users/leakmaker', "/api/v1/products/{$product->slug}/comments", '/api/v1/search?q=leak',
    ];

    foreach ([false, true] as $signedIn) {
        $signedIn ? Sanctum::actingAs(User::factory()->create()) : app('auth')->forgetGuards();

        foreach ($reads as $uri) {
            // When the public read is requested
            $response = $this->getJson($uri)->assertOk();

            // Then none of the analytics or export keys appears at any depth, and neither does the export's id or path
            expect(array_intersect(noLeakKeys($response->json()), NO_LEAK_KEYS))->toBe([], $uri);
            expect($response->getContent())->not->toContain($export->id)->not->toContain('.zip')->not->toContain('exports/');
        }
    }
});

it('puts none of those keys on a vote or re-rank broadcast frame', function () {
    // Given a live product with the same data, a vote cast with real listeners and a re-rank
    $product = dashProduct(DASH_DAY, ['name' => 'Frame Probe']);
    dashBucket($product, '2026-10-21 09:00:00', 0, 55);
    dashReferrer($product, DASH_DAY, 'x', 55);
    exportFor($product, 'ready');
    app('test.reverb.requests')->exchangeArray([]);

    // When a vote is cast and the scores are recomputed
    app(CastVote::class)->handle(User::factory()->create(), $product->id, new VoteSignals(null, null, null));
    $this->artisan('launchpad:recompute-hot-scores')->assertExitCode(0);

    // Then frames were sent, and no key of theirs (the payload is JSON inside the frame) is one of the private ones
    $frames = array_values(app('test.reverb.requests')->getArrayCopy());
    expect($frames)->not->toBeEmpty();
    foreach ($frames as $request) {
        $body = json_decode((string) $request->getBody(), true);
        $data = is_string($body['data'] ?? null) ? json_decode($body['data'], true) : [];
        expect(array_intersect(noLeakKeys($body), NO_LEAK_KEYS))->toBe([])->and(array_intersect(noLeakKeys($data), NO_LEAK_KEYS))->toBe([]);
        expect((string) $request->getBody())->not->toContain('.zip');
    }
});
