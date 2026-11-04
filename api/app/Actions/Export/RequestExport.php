<?php

namespace App\Actions\Export;

use App\Enums\ExportStatus;
use App\Jobs\GenerateExport;
use App\Models\Export;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A maker asks for an export of one of their products. At most one export per product is queued, processing or ready (a
 * partial unique index): a request while one is in flight returns it, a request after a ready one replaces it (the old row
 * expires, its file is deleted after the commit), a request after a failed or expired one starts at once.
 *
 * Lock order: the actor's user row `FOR SHARE`, then export rows. No product row is taken (the product is read plainly:
 * export rows only reference it, and a product edit never waits for an export). The old file is deleted and the job is
 * dispatched only after the transaction, and a dispatch failure never undoes the committed request (the sweeper
 * re-dispatches a stuck queued row).
 */
final class RequestExport
{
    public function __construct(private readonly ExportAccess $access, private readonly ExportFiles $files) {}

    /**
     * @return array{export: Export, created: bool}
     *
     * @throws ModelNotFoundException
     * @throws AuthorizationException
     */
    public function handle(User $actor, string $productId): array
    {
        $product = $this->access->product($actor, $productId);
        $productKey = (string) $product->getKey();

        /** @var array{export: Export, created: bool, replaced: ?Export} $result */
        $result = DB::transaction(function () use ($actor, $productKey): array {
            $member = DB::table('users')->where('id', $actor->getKey())->sharedLock()->first(['id', 'deleted_at', 'suspended_at']);

            if ($member === null || $member->deleted_at !== null || $member->suspended_at !== null) {
                throw new AuthorizationException;
            }

            $inFlight = Export::query()->where('product_id', $productKey)->whereIn('status', ['queued', 'processing'])->lockForUpdate()->first();

            if ($inFlight !== null) {
                return ['export' => $inFlight, 'created' => false, 'replaced' => null];
            }

            $now = CarbonImmutable::now('UTC');
            $replaced = Export::query()->where('product_id', $productKey)->where('status', 'ready')->lockForUpdate()->first();

            // The old file stays referenced by the row until it is really gone (the purge finishes a failed delete).
            $replaced?->forceFill(['status' => ExportStatus::Expired, 'expires_at' => $now, 'updated_at' => $now])->save();

            try {
                // A savepoint: a parallel request that won the race makes the insert fail without poisoning this transaction.
                $export = DB::transaction(fn (): Export => Export::query()->forceCreate([
                    'user_id' => $actor->getKey(), 'product_id' => $productKey, 'status' => ExportStatus::Queued,
                    'file_path' => null, 'file_size' => null, 'row_counts' => null, 'error' => null,
                    'completed_at' => null, 'expires_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                $winner = Export::query()->where('product_id', $productKey)->active()->first();

                if ($winner === null) {
                    throw $e;
                }

                return ['export' => $winner, 'created' => false, 'replaced' => $replaced];
            }

            return ['export' => $export, 'created' => true, 'replaced' => $replaced];
        });

        $this->discard($result['replaced']);

        if ($result['created']) {
            $this->dispatch($result['export']);
        }

        return ['export' => $result['export'], 'created' => $result['created']];
    }

    /** The replaced export's file, after the commit. A failure leaves the path on the row for the purge to finish. */
    private function discard(?Export $replaced): void
    {
        $path = $replaced?->file_path;

        if ($replaced === null || $path === null || ! $this->files->delete($path)) {
            return;
        }

        try {
            DB::table('exports')->where('id', $replaced->getKey())->update(['file_path' => null]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function dispatch(Export $export): void
    {
        try {
            GenerateExport::dispatch((string) $export->getKey())->afterCommit();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
