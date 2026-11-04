<?php

namespace App\Actions\Report;

use App\Actions\Product\TakeDownProduct;
use App\Enums\ProductStatus;
use App\Enums\ReportAction;
use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Exceptions\ProductRuleViolation;
use App\Models\Comment;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ResolveReport
{
    public function __construct(private readonly CloseReports $reports, private readonly TakeDownProduct $takeDownProduct) {}

    /**
     * Staff judge one reported target once: every open report of it is closed together, and the target changes only
     * if the action says so AND at least one open report was locked (staff cannot remove an unreported comment).
     *
     * Everything runs in one transaction in the global lock order: the actor's role is read first (a plain read, no
     * lock), then the product row, then for a comment its top-level parent and the comment, then the target's open
     * report rows (leaf rows, id order), and only then the audit rows (the actor row, FOR KEY SHARE). A target that
     * is already in the wanted state (a comment removed or deleted meanwhile, a product already taken down) applies
     * nothing and its reports are closed as `resolved`, so a replay or a late report can never get stuck. A replay
     * finds no open reports and answers `closed: 0` without an audit row.
     *
     * @param  string  $type  'comment' or 'product' (anything else is a programming error)
     *
     * @throws AuthorizationException when the actor is not (or no longer) active staff, or `take_down` without being an admin
     * @throws ModelNotFoundException the uniform 404: unknown or non-ULID id, a draft product, a comment on one
     * @throws ProductRuleViolation 409 `invalid_status` when `take_down` meets a product that is neither live nor taken down
     * @throws InvalidArgumentException when the caller combined an action with the wrong type or left out a required note
     */
    public function handle(User $actor, string $type, string $targetId, ReportAction $action, ?string $note): ResolveOutcome
    {
        if (! in_array($type, ['comment', 'product'], true)
            || ($action->forType() !== null && $action->forType() !== $type)
            || ($action->needsNote() && ($note === null || $note === ''))) {
            throw new InvalidArgumentException('The action does not fit the target type, or its note is missing.');
        }

        if (! Str::isUlid($targetId)) {
            throw (new ModelNotFoundException)->setModel($type === 'product' ? Product::class : Comment::class);
        }

        return DB::transaction(function () use ($actor, $type, $targetId, $action, $note): ResolveOutcome {
            // The role is read from the database, never from the (possibly stale) request model.
            $this->assertStillStaff($actor, $action);

            $commentRow = null;
            $productId = $targetId;

            if ($type === 'comment') {
                $commentRow = DB::table('comments')->where('id', $targetId)->first(['product_id', 'parent_id']);

                if ($commentRow === null) {
                    throw (new ModelNotFoundException)->setModel(Comment::class);
                }

                $productId = $commentRow->product_id;
            }

            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();

            // Drafts are the founder's private work: the same 404 as an unknown id.
            if ($product === null || $product->status === ProductStatus::Draft) {
                throw (new ModelNotFoundException)->setModel(Product::class);
            }

            $parent = null;
            $comment = null;

            if ($commentRow !== null) {
                $parent = $commentRow->parent_id === null ? null : Comment::query()->whereKey($commentRow->parent_id)->lockForUpdate()->first();
                $comment = Comment::query()->whereKey($targetId)->lockForUpdate()->first();

                if ($comment === null) {
                    throw (new ModelNotFoundException)->setModel(Comment::class);
                }
            }

            $openReports = $this->reports->lockOpen($type, $targetId);

            // The locks may have taken a while (a busy product): a reviewer suspended or demoted meanwhile must not write.
            $this->assertStillStaff($actor, $action);

            // No open report, no change: a replay, or staff trying to act on something nobody reported.
            if ($openReports->isEmpty()) {
                return new ResolveOutcome(0, false, $type, $targetId, $action);
            }

            $changed = match ($action) {
                ReportAction::RemoveComment => $this->removeComment($actor, $comment, $parent, (string) $note),
                ReportAction::TakeDown => $this->takeDown($actor, $product, (string) $note),
                ReportAction::Dismiss => false,
            };

            $closed = $this->reports->close(
                $openReports,
                $action === ReportAction::Dismiss ? ReportStatus::Dismissed : ReportStatus::Resolved,
                $actor,
                $note,
            );

            // Same transaction as the changes. Meta holds ids, enum values and the staff-written note, never names or body text.
            if ($changed && $comment !== null) {
                Audit::record('comment.removed', $actor, $comment, ['product_id' => $comment->product_id, 'reason' => $comment->removed_reason]);
            }

            Audit::record(
                $action === ReportAction::Dismiss ? 'report.dismissed' : 'report.resolved',
                $actor,
                $comment ?? $product,
                ['type' => $type, 'reports' => $closed, 'action' => $action->value, 'note' => $note],
            );

            return new ResolveOutcome($closed, $changed, $type, $targetId, $action);
        });
    }

    /**
     * A plain read of the actor's role (and that they are not suspended or deleted), run twice: first thing, and again
     * after the last lock, just before anything is written.
     *
     * @throws AuthorizationException
     */
    private function assertStillStaff(User $actor, ReportAction $action): void
    {
        $role = Role::tryFrom((string) DB::table('users')->where('id', $actor->getKey())->whereNull('deleted_at')->whereNull('suspended_at')->value('role'));

        if ($role === null || ! $role->isStaff() || ($action === ReportAction::TakeDown && $role !== Role::Admin)) {
            throw new AuthorizationException;
        }
    }

    /**
     * Removes a comment that is still counted: a removed or deleted one is left as it is. Counters move with relative
     * updates under the product lock held by the caller.
     */
    private function removeComment(User $actor, ?Comment $comment, ?Comment $parent, string $note): bool
    {
        if ($comment === null || $comment->removed_at !== null || $comment->deleted_at !== null) {
            return false;
        }

        $now = now();
        $comment->forceFill([
            'removed_at' => $now,
            'removed_by' => $actor->getKey(),
            // The column is varchar(255); the full note stays in the report's resolution_note.
            'removed_reason' => Str::limit($note, 255, ''),
            'updated_at' => $now,
        ])->save();

        DB::table('products')->where('id', $comment->product_id)->update(['comments_count' => DB::raw('comments_count - 1')]);

        if ($parent !== null) {
            DB::table('comments')->where('id', $parent->getKey())->update(['replies_count' => DB::raw('replies_count - 1')]);
        }

        return true;
    }

    /** A live product is taken down by the existing action (nested: a savepoint); one that already is, stays as it is. */
    private function takeDown(User $actor, Product $product, string $note): bool
    {
        if ($product->status === ProductStatus::TakenDown) {
            return false;
        }

        if ($product->status !== ProductStatus::Live) {
            throw ProductRuleViolation::invalidStatus();
        }

        $this->takeDownProduct->handle($actor, $product, $note);

        return true;
    }
}
