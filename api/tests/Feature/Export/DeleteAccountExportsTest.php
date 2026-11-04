<?php

use App\Actions\Auth\DeleteAccount;
use App\Actions\Export\ExportFiles;
use App\Models\Export;
use App\Models\Product;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('exports');
});

/** A member whose only product is a rejected one (deletion is allowed) with a ready export and its file. */
function deleteExportsMember(): array
{
    $product = Product::factory()->rejected()->create();
    $export = exportFor($product, 'ready');
    $path = exportStoreFile($export);

    return [$product->maker, $export, $path];
}

it('deletes the export rows of the deleted member and their files, and nobody else\'s', function () {
    // Given a member with a ready and a failed export, and another member's ready export
    [$member, $export, $path] = deleteExportsMember();
    $failed = exportFor(Product::factory()->takenDown()->create(['maker_id' => $member->id]), 'failed');
    $other = exportFor(Product::factory()->live()->create(), 'ready');
    $otherPath = exportStoreFile($other);

    // When the account is deleted
    expect(app(DeleteAccount::class)->handle($member))->toBeTrue();

    // Then the member's rows and file are gone, the other member's are untouched
    expect(Export::query()->whereKey([$export->id, $failed->id])->count())->toBe(0);
    Storage::disk('exports')->assertMissing($path);
    expect(Export::query()->whereKey($other->id)->exists())->toBeTrue();
    Storage::disk('exports')->assertExists($otherPath);
});

it('removes the files only after the transaction committed', function () {
    // Given a member with a ready export and a files double that records the transaction level
    [$member] = deleteExportsMember();
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

    // When the account is deleted
    app(DeleteAccount::class)->handle($member);

    // Then the delete ran at the baseline level, once
    expect($levels->getArrayCopy())->toBe([$baseline]);
});

it('keeps the deletion when a file cannot be removed and reports it', function () {
    // Given a member whose file cannot be removed
    Exceptions::fake();
    [$member] = deleteExportsMember();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('delete')->andThrow(new RuntimeException('disk down'));
    Storage::shouldReceive('disk')->with('exports')->andReturn($disk);
    Storage::makePartial();

    // When the account is deleted
    $deleted = app(DeleteAccount::class)->handle($member);

    // Then the account is deleted, the rows are gone and the failure was reported
    expect($deleted)->toBeTrue()->and(Export::query()->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

it('lets a build that finishes after the deletion find its row gone and drop its own file', function () {
    // Given a queued export whose owner is deleted while the build runs
    $product = Product::factory()->rejected()->create();
    $export = exportFor($product, 'queued');
    exportBindBuilder(function (Closure $real, Export $e) use ($product) {
        $built = $real();
        app(DeleteAccount::class)->handle($product->maker);

        return $built;
    });

    // When the job runs
    exportGenerate($export);

    // Then the row is gone and no file is left on the disk
    expect(Export::query()->count())->toBe(0);
    expect(Storage::disk('exports')->allFiles())->toBe([]);
});
