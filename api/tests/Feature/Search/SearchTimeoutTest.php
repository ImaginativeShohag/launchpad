<?php

use App\Actions\Search\SearchProducts;
use App\Actions\Search\SearchQuery;
use App\Enums\SearchSort;
use App\Exceptions\SearchTimedOut;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/** A search whose statement runs a deliberately slow or deliberately broken query inside the capped transaction. */
final class SearchTimeoutTestAction extends SearchProducts
{
    public static string $sql = 'select pg_sleep(0.2)';

    protected function afterSettings(): void
    {
        DB::select(self::$sql);
    }
}

afterEach(function () {
    SearchTimeoutTestAction::$sql = 'select pg_sleep(0.2)';
    config(['launchpad.search_timeout_ms' => 2000]);
});

it('turns a statement timeout (SQLSTATE 57014) into SearchTimedOut, and the next call works', function () {
    // Given a product, a 5 ms cap and a search that sleeps inside the capped transaction
    searchLive('Quokka Tool');
    config(['launchpad.search_timeout_ms' => 5]);
    $slow = new SearchTimeoutTestAction;

    // When it runs
    $run = fn () => $slow->handle(SearchQuery::fromString('quokka'), null, SearchSort::Relevance, 20, null);

    // Then it throws the domain exception
    expect($run)->toThrow(SearchTimedOut::class);

    // And with the cap restored a normal search works in the same test (the setting was rolled back with the savepoint)
    config(['launchpad.search_timeout_ms' => 2000]);
    SearchTimeoutTestAction::$sql = 'select 1';
    expect($run()->products)->toHaveCount(1);
});

it('rethrows any other database error untouched, decided on the SQLSTATE only', function () {
    // Given a search that fails with division by zero (22012)
    SearchTimeoutTestAction::$sql = 'select 1/0';
    $slow = new SearchTimeoutTestAction;

    // When it runs
    $caught = null;
    try {
        $slow->handle(SearchQuery::fromString('quokka'), null, SearchSort::Relevance, 20, null);
    } catch (Throwable $e) {
        $caught = $e;
    }

    // Then it is the original QueryException with its own SQLSTATE
    expect($caught)->toBeInstanceOf(QueryException::class)->and($caught->errorInfo[0])->toBe('22012');
});

it('answers 503 with Retry-After over HTTP, reports nothing, and implements ShouldntReport', function () {
    // Given a slow search bound in the container and a 5 ms cap
    searchLive('Quokka Tool');
    config(['launchpad.search_timeout_ms' => 5]);
    app()->bind(SearchProducts::class, fn () => new SearchTimeoutTestAction);
    Exceptions::fake();

    // When the endpoint is called
    $response = $this->getJson('/api/v1/search?q=quokka');

    // Then it is a 503 with the code and Retry-After, and nothing is reported as an error
    $response->assertStatus(503)->assertHeader('Retry-After', '2')->assertJson(['code' => 'search_timeout']);
    Exceptions::assertNothingReported();
    expect(SearchTimedOut::make())->toBeInstanceOf(ShouldntReport::class);
});

it('sets the statement cap and the trigram threshold inside its own transaction with bound values', function () {
    // Given a product
    searchLive('Quokka Tool');

    // When a search with a query runs
    $sql = searchCaptureSql(fn () => searchRun('quokka'));
    $settings = array_values(array_filter($sql, fn (string $s) => str_contains($s, 'set_config')));

    // Then both settings are transaction-local (true) and carry the configured values
    expect($settings)->toBe([
        "select set_config('statement_timeout', '2000', true)",
        "select set_config('pg_trgm.word_similarity_threshold', '0.5', true)",
    ]);
});
