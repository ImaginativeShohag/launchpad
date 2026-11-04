<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

it('rejects passwords shorter than 12 characters and accepts a long one', function () {
    // Given the application's default password rule
    $check = fn (string $password): bool => Validator::make(['password' => $password], ['password' => Password::defaults()])->passes();

    // When a short and a long password are checked
    // Then only the long one passes
    expect($check('short-pass1'))->toBeFalse();
    expect($check('correct-horse-battery'))->toBeTrue();
});

it('forbids lazy loading outside production', function () {
    // Given a list of members loaded without their links
    User::factory()->count(2)->create();
    $user = User::query()->get()->first();

    // When the relation is touched
    // Then strict mode throws instead of silently running an N+1 query
    expect(fn () => $user->links)->toThrow(LazyLoadingViolationException::class);
});

it('refuses destructive artisan commands once the app boots as production', function () {
    // Given the app boots its provider as production
    $provider = new AppServiceProvider($this->app);
    $this->app->detectEnvironment(fn () => 'production');

    try {
        $provider->boot();

        // When a destructive command is attempted
        // Then it is refused before it touches any table
        $this->artisan('migrate:fresh')->assertExitCode(1);
    } finally {
        // And the guard is lifted again for the rest of the suite
        $this->app->detectEnvironment(fn () => 'testing');
        $provider->boot();
    }

    expect(DB::getFacadeRoot()->getDefaultConnection())->toBe('pgsql');
    expect(User::query()->count())->toBe(0);
});
