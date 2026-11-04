<?php

namespace App\Actions\Report;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\SettingKey;
use App\Exceptions\CommunityRuleViolation;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateReport
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * A member reports a comment or a product. Reports never hide anything.
     *
     * Order: plain reads first (target 404, own content 403), then one transaction in the global lock order
     * product (FOR SHARE) -> comment (FOR SHARE) -> [report rows: read, not locked] -> reporter user row (FOR UPDATE, last).
     * The visibility checks are RE-RUN on the locked rows, so a DeleteComment (which closes the comment's reports
     * under product + comment FOR UPDATE) or a take-down that commits between the plain check and the insert is seen:
     * the report is refused with the uniform 404 instead of being left open on a dead target. The reporter row lock
     * serialises one member's parallel creates, so the open cap and the existing-report answer cannot be raced by that
     * member. Then: an existing OPEN report is returned (idempotent, also at the cap), an earlier DISMISSED report is
     * 409, the cap is 409, the insert runs in a savepoint (a unique violation is the existing report).
     *
     * @param  'comment'|'product'  $type
     * @return array{report: Report, created: bool}
     *
     * @throws ModelNotFoundException a hidden, removed, deleted or unknown target: one 404
     * @throws CommunityRuleViolation `own_content`, `already_reviewed` or `report_limit`
     * @throws AuthorizationException when the reporter was suspended or deleted since the request was authorised
     */
    public function handle(User $reporter, string $type, string $targetId, ReportReason $reason, ?string $details): array
    {
        $productId = $this->assertReportable($reporter, $type, $targetId);

        return DB::transaction(function () use ($reporter, $type, $targetId, $productId, $reason, $details): array {
            $this->lockTarget($type, $targetId, $productId);

            // Last in the order, and exclusive: one member's creates run one after the other.
            $state = DB::table('users')->where('id', $reporter->getKey())->lockForUpdate()->first(['deleted_at', 'suspended_at']);

            if ($state === null || $state->deleted_at !== null || $state->suspended_at !== null) {
                throw new AuthorizationException;
            }

            $existing = $this->openReport($reporter, $type, $targetId);

            if ($existing !== null) {
                return ['report' => $existing, 'created' => false];
            }

            $dismissed = Report::query()->where('reporter_id', $reporter->getKey())
                ->where('reportable_type', $type)->where('reportable_id', $targetId)
                ->where('status', ReportStatus::Dismissed->value)->exists();

            if ($dismissed) {
                throw CommunityRuleViolation::alreadyReviewed();
            }

            if (Report::query()->open()->where('reporter_id', $reporter->getKey())->count() >= $this->settings->integer(SettingKey::CommunityReportOpenCap)) {
                throw CommunityRuleViolation::reportLimit();
            }

            try {
                // A savepoint: a unique violation must not poison the surrounding transaction (25P02).
                $report = DB::transaction(function () use ($reporter, $type, $targetId, $reason, $details): Report {
                    $now = now();

                    return tap((new Report)->forceFill([
                        'reporter_id' => $reporter->getKey(),
                        'reportable_type' => $type,
                        'reportable_id' => $targetId,
                        'reason' => $reason,
                        'details' => $details,
                        'status' => ReportStatus::Open,
                        'handled_by' => null,
                        'handled_at' => null,
                        'resolution_note' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]))->save();
                });
            } catch (UniqueConstraintViolationException) {
                // Another writer got in despite the lock (for example a staff-side insert): that is the existing report.
                $existing = $this->openReport($reporter, $type, $targetId);

                if ($existing === null) {
                    throw new \LogicException('The open report that violated the unique index vanished.');
                }

                return ['report' => $existing, 'created' => false];
            }

            return ['report' => $report, 'created' => true];
        });
    }

    /**
     * Plain reads, no row locks. The target must be reportable (public product, counted comment of a live author on a
     * public product) and not the reporter's own.
     *
     * @return string the id of the product the target is (or lives on)
     *
     * @throws ModelNotFoundException
     * @throws CommunityRuleViolation
     */
    private function assertReportable(User $reporter, string $type, string $targetId): string
    {
        if (! Str::isUlid($targetId)) {
            throw (new ModelNotFoundException)->setModel($type === 'product' ? Product::class : Comment::class);
        }

        if ($type === 'product') {
            $product = Product::visible()->whereMakerAvailable()->whereKey($targetId)->first();

            if ($product === null) {
                throw (new ModelNotFoundException)->setModel(Product::class);
            }

            if ($product->maker_id === $reporter->getKey()) {
                throw CommunityRuleViolation::ownContent();
            }

            return (string) $product->getKey();
        }

        $comment = Comment::query()->counted()->whereAuthorAlive()->whereKey($targetId)->first();

        if ($comment === null || ! Product::visible()->whereMakerAvailable()->whereKey($comment->product_id)->exists()) {
            throw (new ModelNotFoundException)->setModel(Comment::class);
        }

        if ($comment->user_id === $reporter->getKey()) {
            throw CommunityRuleViolation::ownContent();
        }

        return $comment->product_id;
    }

    /**
     * FOR SHARE on the product, then on the comment, and the reportability checks again on the locked rows. Share
     * locks keep the target from changing under us (every writer that changes it takes the same rows FOR UPDATE) and
     * do not serialise reporters against each other.
     *
     * @throws ModelNotFoundException when the target stopped being reportable since the plain check
     */
    private function lockTarget(string $type, string $targetId, string $productId): void
    {
        $product = Product::visible()->whereMakerAvailable()->whereKey($productId)->sharedLock()->first();

        if ($product === null) {
            throw (new ModelNotFoundException)->setModel(Product::class);
        }

        if ($type === 'comment' && Comment::query()->counted()->whereAuthorAlive()->whereKey($targetId)->sharedLock()->first() === null) {
            throw (new ModelNotFoundException)->setModel(Comment::class);
        }
    }

    private function openReport(User $reporter, string $type, string $targetId): ?Report
    {
        return Report::query()->open()->where('reporter_id', $reporter->getKey())
            ->where('reportable_type', $type)->where('reportable_id', $targetId)->first();
    }
}
