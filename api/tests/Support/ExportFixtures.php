<?php

use App\Actions\Dashboard\RankHistory;
use App\Actions\Dashboard\Series;
use App\Actions\Dashboard\Summary;
use App\Actions\Export\BuildExportFiles;
use App\Actions\Export\ExportFiles;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A live product of a fresh available maker signed in for the rest of the test. */
function exportMakerProduct(array $attributes = []): Product
{
    $maker = signedInMember();

    return Product::factory()->live()->create(['maker_id' => $maker->id, ...$attributes]);
}

/** An export row of $product in the state the factory method names ('ready' also writes its file on the faked disk). */
function exportFor(Product $product, string $state = 'queued', array $attributes = []): Export
{
    $factory = Export::factory()->state(['product_id' => $product->id, 'user_id' => $product->maker_id]);

    $factory = match ($state) {
        'queued' => $factory,
        'processing' => $factory->processing(),
        'ready' => $factory->ready(),
        'failed' => $factory->failed(),
        'expired' => $factory->expired(),
    };

    $export = $factory->create($attributes);

    // A ready row points at its deterministic key ({product_id}/{export_id}.zip), as GenerateExport writes it.
    if ($state === 'ready' && ! array_key_exists('file_path', $attributes)) {
        DB::table('exports')->where('id', $export->id)->update(['file_path' => ExportFiles::path($export)]);
        $export->refresh();
    }

    return $export;
}

/** Puts bytes at the deterministic path of $export on the exports disk and returns that path. */
function exportStoreFile(Export $export, string $bytes = 'zip-bytes'): string
{
    $path = ExportFiles::path($export);
    ExportFiles::disk()->put($path, $bytes);

    return $path;
}

/** @return list<string> the SQL of every locking statement (FOR UPDATE / FOR SHARE / FOR KEY SHARE) the callback ran */
function exportLockingStatements(Closure $callback): array
{
    $seen = [];
    DB::listen(function ($query) use (&$seen): void {
        if (preg_match('/\bfor (update|share|key share)\b/i', $query->sql) === 1) {
            $seen[] = strtolower($query->sql);
        }
    });
    $callback();

    return $seen;
}

/** A user row's `suspended_at` set, as an admin's suspension would leave it. */
function exportSuspend(User $user): void
{
    DB::table('users')->where('id', $user->id)->update(['suspended_at' => now()]);
}

/** A valid-looking ULID that belongs to nothing. */
function exportUnknownId(): string
{
    return strtolower((string) Str::ulid());
}

/** Runs the generation job inline (handle only: no queue, no unique lock). */
function exportGenerate(Export|string $export): void
{
    $id = $export instanceof Export ? $export->id : $export;
    app()->call([new GenerateExport($id), 'handle']);
}

/** @return array<string, string> entry name => contents of the stored zip of $export (the exports disk is faked) */
function exportZipFiles(Export $export): array
{
    $export->refresh();
    $zip = new ZipArchive;
    $path = ExportFiles::disk()->path((string) $export->file_path);
    expect($zip->open($path))->toBeTrue();
    $files = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $files[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
    }
    $zip->close();

    return $files;
}

/** @return list<list<string>> the data rows (header dropped) of a CSV string without embedded line breaks */
function exportCsvRows(string $csv): array
{
    $lines = array_values(array_filter(explode("\r\n", $csv), fn (string $l) => $l !== ''));

    return array_map(fn (string $l) => str_getcsv($l, ',', '"', ''), array_slice($lines, 1));
}

/** @return array<string, string> key => value of summary.csv */
function exportSummaryMap(string $summary): array
{
    $map = [];
    foreach (exportCsvRows($summary) as [$key, $value]) {
        $map[$key] = $value;
    }

    return $map;
}

/** @return list<string> the lpx* temp files currently in the system temp dir */
function exportTempFiles(): array
{
    return glob(sys_get_temp_dir().'/lpx*') ?: [];
}

/**
 * Binds a BuildExportFiles whose handle() is wrapped by $around(Closure $real, Export $export, CarbonImmutable $asOf): array.
 * $around may fail, change the data or run side effects before/after calling $real().
 */
function exportBindBuilder(Closure $around): void
{
    app()->bind(BuildExportFiles::class, fn () => new class($around) extends BuildExportFiles
    {
        public function __construct(private readonly Closure $around)
        {
            parent::__construct(app(Summary::class), app(Series::class), app(RankHistory::class));
        }

        public function handle(Export $export, CarbonImmutable $asOf): array
        {
            return ($this->around)(fn () => parent::handle($export, $asOf), $export, $asOf);
        }
    });
}
