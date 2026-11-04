<?php

namespace App\Actions\Review;

use App\Enums\ProductStatus;
use App\Enums\ReviewDecision;
use App\Enums\Role;
use App\Events\ProductApproved;
use App\Events\ProductChangesRequested;
use App\Events\ProductRejected;
use App\Exceptions\ProductRuleViolation;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideReview
{
    /**
     * Locks the product row, requires `pending`, writes the review, the audit row and the new status together and
     * fires the matching event after commit. A second reviewer deciding at the same moment waits for the lock, then
     * sees a status that is no longer pending (409).
     *
     * Only the product row is locked here; the audit and review inserts take FOR KEY SHARE on the reviewer's user
     * row, which only a concurrent deletion of that reviewer would contend for (see the lock order in Task 13).
     *
     * @param  string  $seenSubmittedAt  the product's submitted_at as the reviewer saw it (microsecond ISO string)
     *
     * @throws AuthorizationException when the reviewer is no longer an active reviewer or admin
     * @throws ProductRuleViolation 409 when not pending, 409 `stale_submission` when resubmitted since, 403 `own_product` for the reviewer's own product, 409 `maker_unavailable` when the maker was deleted
     * @throws ValidationException when a rejection or change request has no note
     */
    public function handle(User $reviewer, Product $product, ReviewDecision $decision, ?string $note, string $seenSubmittedAt): ProductReview
    {
        return DB::transaction(function () use ($reviewer, $product, $decision, $note, $seenSubmittedAt): ProductReview {
            // Lock order: the staff check first (a plain read, no lock), then the product row.
            // The reviewer model may be stale (role revoked or account suspended since authorisation).
            $role = DB::table('users')->where('id', $reviewer->getKey())->whereNull('deleted_at')->whereNull('suspended_at')->value('role');

            if (! in_array($role, [Role::Reviewer->value, Role::Admin->value], true)) {
                throw new AuthorizationException;
            }

            $locked = Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

            // Drafts are the founder's private work: staff get the same 404 as for an unknown id, as on the detail view.
            if ($locked->status === ProductStatus::Draft) {
                throw (new ModelNotFoundException)->setModel(Product::class);
            }

            $locked->transitionTo($decision->toStatus());

            if ($locked->maker_id === $reviewer->getKey()) {
                throw ProductRuleViolation::ownProduct();
            }

            // Defensive: DeleteAccount refuses members with a pending product, so this should not happen; never approve
            // (or decide) a product whose maker was anonymised. A plain read: no user lock after the product lock here.
            if (DB::table('users')->where('id', $locked->maker_id)->whereNotNull('deleted_at')->exists()) {
                throw ProductRuleViolation::makerUnavailable();
            }

            // A withdraw, edit and resubmit leaves the status `pending` but changes what the reviewer saw; only the
            // submitted_at they looked at (compared at microsecond precision) proves the content is unchanged.
            if ($locked->submitted_at?->toIso8601ZuluString('microsecond') !== $seenSubmittedAt) {
                throw ProductRuleViolation::staleSubmission();
            }

            if ($decision->needsNote() && blank($note)) {
                throw ValidationException::withMessages(['note' => ['A note is required for this decision.']]);
            }

            $review = $locked->reviews()->create([
                'reviewer_id' => $reviewer->getKey(),
                'decision' => $decision,
                'note' => $note,
                'created_at' => now(),
            ]);

            if ($decision === ReviewDecision::Approved) {
                $locked->approved_at = now();
            }
            $locked->save();

            // The audit row IS part of the operation, so it is not swallowed; the note stays out of `meta`.
            Audit::record('product.reviewed', $reviewer, $locked, ['decision' => $decision->value, 'review_id' => $review->getKey()]);

            $productId = (string) $locked->getKey();
            $actorId = (string) $reviewer->getKey();
            $reviewId = (string) $review->getKey();

            match ($decision) {
                ReviewDecision::Approved => ProductApproved::dispatch($productId, $actorId, $reviewId),
                ReviewDecision::Rejected => ProductRejected::dispatch($productId, $actorId, $reviewId),
                ReviewDecision::ChangesRequested => ProductChangesRequested::dispatch($productId, $actorId, $reviewId),
            };

            $product->setRawAttributes($locked->getAttributes(), true);

            return $review;
        });
    }
}
