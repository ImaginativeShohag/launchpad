<?php

use App\Actions\Export\ExportFiles;
use App\Actions\Export\RequestExport;
use App\Enums\ExportStatus;
use App\Enums\Role;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(dashNow());
    Queue::fake();
    Storage::fake('exports');
});

afterEach(function () {
    Export::flushEventListeners();
});

it('creates a queued export, answers 202 and dispatches the job once', function () {
    // Given a maker with a live product
    $product = exportMakerProduct();

    // When they ask for an export
    $response = $this->postJson("/api/v1/me/products/{$product->id}/exports");

    // Then it is accepted as queued, stored for the maker and the product, and one job is pushed with its id
    $response->assertStatus(202)->assertJsonPath('data.status', 'queued')->assertJsonPath('data.product_id', $product->id);
    $export = Export::query()->firstOrFail();
    expect($export->user_id)->toBe($product->maker_id)->and($export->product_id)->toBe($product->id)->and($export->status)->toBe(ExportStatus::Queued);
    Queue::assertPushed(GenerateExport::class, 1);
    Queue::assertPushed(GenerateExport::class, fn (GenerateExport $job) => $job->exportId === $export->id);
});

it('does not dispatch when the transaction fails after the insert', function () {
    // Given a maker and a model hook that fails right after the row is inserted
    $product = exportMakerProduct();
    Export::created(function () {
        throw new RuntimeException('boom');
    });

    // When the action runs
    $run = fn () => app(RequestExport::class)->handle($product->maker, $product->id);

    // Then it fails, nothing was written and nothing was dispatched
    expect($run)->toThrow(RuntimeException::class);
    expect(Export::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('returns the export in flight with 200 and starts nothing new', function (string $state) {
    // Given a maker with an export that is queued or processing
    $product = exportMakerProduct();
    $existing = exportFor($product, $state);

    // When they ask again
    $response = $this->postJson("/api/v1/me/products/{$product->id}/exports");

    // Then they get the same export, no row is added and no job is pushed
    $response->assertOk()->assertJsonPath('data.id', $existing->id)->assertJsonPath('data.status', $state);
    expect(Export::query()->count())->toBe(1);
    Queue::assertNothingPushed();
})->with(['queued', 'processing']);

it('replaces a ready export: a new id, the old row expired, its file deleted after the commit', function () {
    // Given a maker with a ready export and a file on the disk
    $product = exportMakerProduct();
    $old = exportFor($product, 'ready');
    $path = exportStoreFile($old);
    $baseline = DB::transactionLevel();
    $levels = new ArrayObject;
    app()->bind(ExportFiles::class, fn () => new class($levels) extends ExportFiles
    {
        public function __construct(private ArrayObject $levels) {}

        public function delete(string $path): bool
        {
            $this->levels[] = DB::transactionLevel();

            return parent::delete($path);
        }
    });

    // When they ask for another
    $response = $this->postJson("/api/v1/me/products/{$product->id}/exports");

    // Then a new export is accepted, the old one is expired with its path cleared, and the delete ran outside the transaction
    $response->assertStatus(202);
    expect($response->json('data.id'))->not->toBe($old->id);
    $old->refresh();
    expect($old->status)->toBe(ExportStatus::Expired)->and($old->file_path)->toBeNull()->and($old->expires_at->lte(now()))->toBeTrue();
    Storage::disk('exports')->assertMissing($path);
    expect($levels->getArrayCopy())->toBe([$baseline]);
    expect(Export::query()->active()->count())->toBe(1);
    Queue::assertPushed(GenerateExport::class, 1);
});

it('keeps the path on the old row when its file cannot be deleted, and still accepts the request', function () {
    // Given a ready export whose file cannot be deleted
    $product = exportMakerProduct();
    $old = exportFor($product, 'ready');
    exportStoreFile($old);
    app()->bind(ExportFiles::class, fn () => new class extends ExportFiles
    {
        public function delete(string $path): bool
        {
            return false;
        }
    });

    // When a new export is requested
    $response = $this->postJson("/api/v1/me/products/{$product->id}/exports");

    // Then the request succeeds and the expired row keeps its path for the purge to finish
    $response->assertStatus(202);
    $old->refresh();
    expect($old->status)->toBe(ExportStatus::Expired)->and($old->file_path)->not->toBeNull();
});

it('reports a disk failure of ExportFiles::delete and answers false instead of throwing', function () {
    // Given a disk that throws on delete
    Exceptions::fake();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('disk down'));
    Storage::shouldReceive('disk')->with('exports')->andReturn($disk);

    // When a file is deleted
    $deleted = app(ExportFiles::class)->delete('a/b.zip');

    // Then it is false and the failure was reported
    expect($deleted)->toBeFalse();
    Exceptions::assertReported(RuntimeException::class);
});

it('starts a new export at once after a failed or expired one', function (string $state) {
    // Given a maker whose last export is final
    $product = exportMakerProduct();
    $old = exportFor($product, $state);

    // When they ask again
    $response = $this->postJson("/api/v1/me/products/{$product->id}/exports");

    // Then a new queued export exists next to the final one
    $response->assertStatus(202);
    expect($response->json('data.id'))->not->toBe($old->id)->and(Export::query()->count())->toBe(2);
    Queue::assertPushed(GenerateExport::class, 1);
})->with(['failed', 'expired']);

it('answers the winner when a parallel request inserted the active row first', function () {
    // Given a maker and a listener that slips a competing export in just before this request inserts its own
    $product = exportMakerProduct();
    $winner = null;
    DB::listen(function ($query) use ($product, &$winner) {
        if ($winner === null && str_contains($query->sql, 'for update') && str_contains($query->sql, '"status" = ') && str_contains(json_encode($query->bindings), 'ready')) {
            $winner = Export::factory()->state(['product_id' => $product->id, 'user_id' => $product->maker_id])->create();
        }
    });

    // When they ask for an export
    $response = $this->postJson("/api/v1/me/products/{$product->id}/exports");

    // Then the unique violation is caught and the winner is answered with 200; there is one active row and no job
    expect($winner)->not->toBeNull();
    $response->assertOk()->assertJsonPath('data.id', $winner->id);
    expect(Export::query()->active()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('allows every product status for the maker', function (string $state) {
    // Given a maker's product in any status
    $maker = signedInMember();
    $product = Product::factory()->{$state}()->create(['maker_id' => $maker->id]);

    // When they ask for an export
    // Then it is accepted
    $this->postJson("/api/v1/me/products/{$product->id}/exports")->assertStatus(202);
})->with(['complete', 'pending', 'approved', 'rejected', 'changesRequested', 'scheduled', 'live', 'takenDown']);

it('accepts a plain draft', function () {
    // Given a maker's draft
    $maker = signedInMember();
    $draft = Product::factory()->create(['maker_id' => $maker->id]);

    // When they ask for an export
    // Then it is accepted
    $this->postJson("/api/v1/me/products/{$draft->id}/exports")->assertStatus(202);
});

it('gives the same 404 for every product that is not the caller\'s', function (Closure $makeId, ?Role $role) {
    // Given a caller (a member, a reviewer or an admin) and a product id that is not theirs
    $caller = $role === null ? signedInMember() : signedInStaff($role);
    $id = $makeId($caller);
    $missing = $this->postJson('/api/v1/me/products/'.exportUnknownId().'/exports');

    // When they ask for an export
    $response = $this->postJson("/api/v1/me/products/{$id}/exports");

    // Then it is the one 404 of a missing product and nothing is created
    $response->assertNotFound();
    expect($response->json())->toEqual($missing->json());
    expect(Export::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'another maker' => [fn () => Product::factory()->live()->create()->id, null],
    'a reviewer on a member product' => [fn () => Product::factory()->live()->create()->id, Role::Reviewer],
    'an admin on a member product' => [fn () => Product::factory()->live()->create()->id, Role::Admin],
    'a soft-deleted own product' => [fn (User $u) => tap(Product::factory()->live()->create(['maker_id' => $u->id]))->delete()->id, null],
    'an unknown ulid' => [fn () => exportUnknownId(), null],
    'not a ulid' => [fn () => 'not-a-ulid', null],
    'a nul byte' => [fn () => '%00', null],
    'two hundred characters' => [fn () => str_repeat('a', 200), null],
]);

it('refuses a guest, an unverified and a suspended member the same way for existing and missing ids', function () {
    // Given a product and a missing id
    $product = Product::factory()->live()->create();
    $missing = exportUnknownId();

    // When a guest asks
    // Then it is 401
    $this->postJson("/api/v1/me/products/{$product->id}/exports")->assertUnauthorized();

    // And an unverified member gets one 403 for both
    Sanctum::actingAs(User::factory()->unverified()->create());
    $a = $this->postJson("/api/v1/me/products/{$product->id}/exports")->assertForbidden();
    $b = $this->postJson("/api/v1/me/products/{$missing}/exports")->assertForbidden();
    expect($a->json())->toEqual($b->json());

    // And a suspended member gets one 403 for both
    Sanctum::actingAs(User::factory()->suspended()->create());
    $c = $this->postJson("/api/v1/me/products/{$product->id}/exports")->assertForbidden();
    $d = $this->postJson("/api/v1/me/products/{$missing}/exports")->assertForbidden();
    expect($c->json())->toEqual($d->json())->and($c->json('code'))->toBe('account_unavailable');
    expect(Export::query()->count())->toBe(0);
});

it('refuses a member who was suspended or deleted after the middleware ran', function (string $column) {
    // Given a maker whose row changed between the middleware and the action
    $product = exportMakerProduct();
    DB::table('users')->where('id', $product->maker_id)->update([$column => now()]);

    // When the action is called directly
    $run = fn () => app(RequestExport::class)->handle($product->maker, $product->id);

    // Then it refuses and writes nothing
    expect($run)->toThrow(AuthorizationException::class);
    expect(Export::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['suspended_at', 'deleted_at']);

it('answers 202 and reports when the dispatch throws', function () {
    // Given a bus that throws
    Exceptions::fake();
    $product = exportMakerProduct();
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('queue down'));
    $this->app->instance(Dispatcher::class, $bus);

    // When they ask for an export
    $response = $this->postJson("/api/v1/me/products/{$product->id}/exports");

    // Then the committed request still answers 202, the row stays queued and the failure was reported
    $response->assertStatus(202)->assertJsonPath('data.status', 'queued');
    expect(Export::query()->firstOrFail()->status)->toBe(ExportStatus::Queued);
    Exceptions::assertReported(RuntimeException::class);
});

it('ignores a body of any shape', function (mixed $body) {
    // Given a maker
    $product = exportMakerProduct();

    // When the POST carries an array, an object, null, a string or a foreign product id
    $response = $this->call('POST', "/api/v1/me/products/{$product->id}/exports", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode($body));

    // Then it is accepted like any other, never a 500
    $response->assertStatus(202);
    expect(Export::query()->count())->toBe(1)->and(Export::query()->value('product_id'))->toBe($product->id);
})->with([[[]], [new stdClass], [null], ['x'], [['product_id' => 'other']], [['export' => [1]]]]);

it('takes the actor row for share first, then export rows for update, and no product or user for update', function () {
    // Given a maker with a ready export (so the replacing path runs too)
    $product = exportMakerProduct();
    exportFor($product, 'ready');

    // When the action runs
    $statements = exportLockingStatements(fn () => app(RequestExport::class)->handle($product->maker, $product->id));

    // Then the first lock is the user row FOR SHARE, the rest are export rows FOR UPDATE, and no product row is locked
    expect($statements[0])->toContain('from "users"')->toContain('for share');
    foreach (array_slice($statements, 1) as $sql) {
        expect($sql)->toContain('from "exports"')->toContain('for update');
    }
    expect(collect($statements)->filter(fn ($sql) => str_contains($sql, '"products"')))->toBeEmpty();
    expect(collect($statements)->filter(fn ($sql) => str_contains($sql, '"users"') && str_contains($sql, 'for update')))->toBeEmpty();
});
