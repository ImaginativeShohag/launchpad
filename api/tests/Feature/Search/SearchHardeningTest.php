<?php

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    searchLive('Quokka Tool', 'Ship things', 'A description of the tool');
    searchLive('Launchpad', 'Launch products');
});

/** @return array<string, array{0: string}> */
function searchInjectionStrings(): array
{
    return [
        'every operator' => ["& | ! : * ( ) < > ' \" \\ % _"],
        'and not' => ['foo & !bar'],
        'drop table' => ["'; DROP TABLE products; --"],
        'colon star' => [':*'],
        'colon star inside' => ['a:*b'],
        'backslash' => ['\\'],
        'emoji only' => ['😀😀😀'],
        'combining marks only' => ["\u{0301}\u{0301}\u{0301}\u{0301}"],
        'fifty character token' => [str_repeat('q', 50)],
        'fifty-one character token' => [str_repeat('q', 51)],
        'nul byte' => ["quo\0kka"],
        'bidi override' => ["quo\u{202E}kka"],
        'percent zero zero' => ['%00'],
        'prefix operator' => ['launch:*'],
        'adjacent operator' => ['launch<->pad'],
        'like wildcards' => ['100%_off'],
        'sql comment' => ['quokka/**/OR/**/1=1'],
        'tsquery syntax' => ["'quokka' & 'tool':*A"],
        'parens' => ['((((quokka))))'],
        'unicode quotes' => ["\u{201C}quokka\u{201D} \u{2018}tool\u{2019}"],
        'huge input' => [str_repeat('a:* & ', 60)],
    ];
}

it('answers 200 or 422, never 500, for injection and syntax strings, and returns only rows that match', function (string $input) {
    // Given the products and their row count
    $before = DB::table('products')->count();

    // When the string is sent through the endpoint
    $response = searchApi('q='.urlencode($input));

    // Then it is 200 or 422, the table and its rows are untouched, and every returned row contains a typed token
    expect($response->status())->toBeIn([200, 422]);
    expect(Schema::hasTable('products'))->toBeTrue()->and(DB::table('products')->count())->toBe($before);

    if ($response->status() === 200) {
        $tokens = searchTokens($input);
        foreach ($response->json('data') as $card) {
            $text = mb_strtolower(implode(' ', [$card['name'], $card['tagline'], $card['description']]));
            expect(collect($tokens)->contains(fn (string $token) => str_contains($text, $token)))->toBeTrue("{$input} returned {$card['name']}");
        }
    }
})->with('searchInjectionStrings');
dataset('searchInjectionStrings', fn () => searchInjectionStrings());

it('does not let a tsquery operator change the match', function () {
    // Given the products
    // When operators sit between words that exist in different products
    // Then they are separators: both words must still match one product
    expect(searchApi('q='.urlencode('description | launch'))->json('data'))->toBe([]);
    expect(searchApi('q='.urlencode('quokka & tool'))->json('data.*.name'))->toBe(['Quokka Tool']);
    expect(searchApi('q='.urlencode('!quokka'))->json('data.*.name'))->toBe(['Quokka Tool']);
});

it('scopes the statement cap and the threshold to its own transaction with bound values', function () {
    // Given a search through the endpoint
    // When the SQL it runs is captured
    $sql = searchCaptureSql(fn () => searchApi('q=quokka')->assertOk());
    $settings = array_values(array_filter($sql, fn (string $s) => str_contains($s, 'set_config')));

    // Then both settings are transaction-local (the third argument is true)
    expect($settings)->toHaveCount(2);
    foreach ($settings as $setting) {
        expect($setting)->toEndWith(', true)');
    }
});

it('writes nothing: no audit, log, event or dispatch in the code, and no row in the write tables', function () {
    // Given the source of the search code
    $files = [...glob(app_path('Actions/Search/*.php')), app_path('Http/Controllers/Api/V1/SearchController.php'), app_path('Http/Requests/Api/V1/SearchRequest.php'), app_path('Exceptions/SearchTimedOut.php')];
    $tables = ['audit_logs', 'auth_events', 'votes', 'product_hourly_stats', 'notifications', 'email_messages'];
    $counts = fn () => array_map(fn (string $table) => DB::table($table)->count(), $tables);
    $before = $counts();

    // When searches run, including a refusal
    searchApi('q=quokka')->assertOk();
    searchApi('q=a')->assertStatus(422);

    // Then none of the code refers to a writer, and no write table gained a row
    foreach ($files as $file) {
        $code = (string) file_get_contents($file);
        foreach (['AuditLog', 'AuthLog', 'Audit::', 'Log::', 'event(', 'dispatch(', '->insert(', '->update(', '->delete(', 'lockForUpdate', 'sharedLock'] as $needle) {
            expect(str_contains($code, $needle))->toBeFalse(basename($file).' mentions '.$needle);
        }
    }
    expect($counts())->toBe($before);
});

it('answers a hidden product by name exactly like a nonsense query', function (string $label) {
    // Given only a product in a non-public state named Quokka Hidden (the shared fixtures are removed: a similar name would match by typo)
    DB::table('products')->delete();
    [$build] = searchHiddenStates()[$label];
    $build();
    $nonsense = searchApi('q=zzxqvbn');

    // When its exact name is searched
    $hidden = searchApi('q='.urlencode('Quokka Hidden'));

    // Then the status and the key structure are identical, and the page is empty
    expect($hidden->status())->toBe($nonsense->status())->toBe(200);
    expect(array_keys($hidden->json()))->toBe(array_keys($nonsense->json()))->and(array_keys($hidden->json('meta')))->toBe(array_keys($nonsense->json('meta')));
    expect($hidden->json('data'))->toBe([])->and($hidden->json('links.next'))->toBeNull()->and($hidden->json('meta.next_cursor'))->toBeNull();
})->with(array_keys(searchHiddenStates()));

it('registers exactly one search route, public GET', function () {
    // Given the registered routes
    // When those whose path mentions search are collected
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'search'));

    // Then there is one, and it is a GET
    expect($routes)->toHaveCount(1)->and($routes->first()->uri())->toBe('api/v1/search');
    expect(Product::query()->count())->toBe(2);
});
