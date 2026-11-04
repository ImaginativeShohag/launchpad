<?php

use App\Models\RiskSignal;

it('takes only key-share locks, products before users, ahead of every insert, and never updates a product', function () {
    // Given a run with findings of several shapes (a ring, a script, a fresh account, a throwaway address)
    antiCheatFreeze();
    antiCheatCases();
    antiCheatConfig();

    // When the job runs in one-product, one-voter chunks under the query recorder (many chunks, so a later chunk cannot hide)
    $queries = antiCheatQueries(fn () => antiCheatDetect(['productChunk' => 1, 'userChunk' => 1]));

    // Then no statement locks for update or updates a product or user
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'for update')))->toBeFalse();
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'update "products"') || str_contains($sql, 'update "users"')))->toBeFalse();

    // And the only user update is the scoring pass's conditional risk_score write, which comes after the last signal insert
    $inserts = array_keys(array_filter($queries, fn (string $sql) => str_contains($sql, 'insert into risk_signals')));
    $scoring = array_keys(array_filter($queries, fn (string $sql) => str_contains($sql, 'update users u set risk_score')));
    expect($scoring)->not->toBeEmpty()->and(min($scoring))->toBeGreaterThan(max($inserts));
    expect(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'update products')))->toBeFalse();

    // And every insert is preceded by its locks in the order products, then users, both ordered by id
    $tokens = '';
    foreach ($queries as $sql) {
        if (str_contains($sql, 'for key share')) {
            expect($sql)->toContain('order by id');
            $tokens .= str_contains($sql, 'from products') ? 'P' : (str_contains($sql, 'from users') ? 'U' : '?');
        } elseif (str_contains($sql, 'insert into risk_signals')) {
            $tokens .= 'I';
        }
    }
    // (a finding names a member, a product or both, so every insert follows PUI, PI or UI; a chunk that dropped its locks would show a bare I)
    expect($tokens)->toMatch('/^(PUI|PI|UI)+$/')->and($tokens)->toContain('PUI')->and($tokens)->not->toContain('?');
    expect(substr_count($tokens, 'I'))->toBeGreaterThanOrEqual(4)->and(RiskSignal::query()->count())->toBe(7);
});
