<?php

namespace App\Actions\Notification;

use App\Enums\ExportStatus;
use App\Enums\NotificationType;
use App\Enums\ProductStatus;
use App\Enums\ReviewDecision;
use App\Models\Comment;
use App\Models\Export;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\Mail\DisplayName;
use Illuminate\Support\Facades\DB;

/**
 * Turns the state behind a domain event into notification drafts. The queued listeners and the hourly reconcile sweep
 * both call these methods and then `RecordNotification`, so they derive identical dedupe and mail keys by construction.
 *
 * Everything is re-read here from ids (events carry ids only). A comment body and a reviewer's note are never read into
 * a draft: only ids, a message key and the cleaned product name.
 */
final class NotificationDrafts
{
    /** The maker's review mail and bell row; null when the review or the product is gone. */
    public static function reviewOutcome(string $reviewId): ?NotificationDraft
    {
        $review = ProductReview::query()->find($reviewId);
        $product = $review === null ? null : Product::query()->find($review->product_id);

        if ($review === null || $product === null) {
            return null;
        }

        $type = match ($review->decision) {
            ReviewDecision::Approved => NotificationType::ReviewApproved,
            ReviewDecision::Rejected => NotificationType::ReviewRejected,
            ReviewDecision::ChangesRequested => NotificationType::ReviewChangesRequested,
        };

        return new NotificationDraft(
            $product->maker_id,
            $type,
            'review:'.$review->id,
            [
                'message_key' => $type->messageKey(), 'product_id' => $product->id, 'review_id' => $review->id,
                'params' => ['product_name' => DisplayName::of($product->name)], 'href' => '/me/products/'.$product->id,
            ],
            'review_outcome:'.$review->id.':'.$product->maker_id,
            ['review_id' => $review->id, 'product_id' => $product->id, 'decision' => $review->decision->value],
        );
    }

    /** "Your product is live"; null unless the product is live with a launch date (a product taken down meanwhile writes nothing). */
    public static function productLive(string $productId): ?NotificationDraft
    {
        $product = Product::query()->find($productId);

        if ($product === null || $product->status !== ProductStatus::Live || $product->launch_date === null) {
            return null;
        }

        $date = $product->launch_date->toDateString();

        return new NotificationDraft(
            $product->maker_id,
            NotificationType::ProductLive,
            'product_live:'.$product->id.':'.$date,
            [
                'message_key' => NotificationType::ProductLive->messageKey(), 'product_id' => $product->id,
                'params' => ['product_name' => DisplayName::of($product->name)], 'href' => '/products/'.$product->slug,
            ],
            'product_live:'.$product->id.':'.$date.':'.$product->maker_id,
            ['product_id' => $product->id, 'launch_date' => $date],
        );
    }

    /**
     * "Your export is ready": null unless the export is still `ready` and its product exists (a replaced, expired or failed export
     * writes nothing). The link is the maker's own dashboard, never a file URL; the key is the export id, so a re-run creates nothing.
     */
    public static function exportReady(string $exportId): ?NotificationDraft
    {
        $export = Export::query()->find($exportId);
        $product = $export === null ? null : Product::query()->find($export->product_id);

        if ($export === null || $product === null || $export->status !== ExportStatus::Ready || $export->completed_at === null || $export->expires_at === null) {
            return null;
        }

        return new NotificationDraft(
            $export->user_id,
            NotificationType::ExportReady,
            'export_ready:'.$export->id,
            [
                'message_key' => NotificationType::ExportReady->messageKey(), 'product_id' => $product->id,
                'params' => ['product_name' => DisplayName::of($product->name), 'days' => max(1, (int) round($export->completed_at->diffInDays($export->expires_at)))],
                'href' => '/me/products/'.$product->id.'/dashboard',
            ],
            'export_ready:'.$export->id.':'.$export->user_id,
            ['export_id' => $export->id, 'product_id' => $product->id],
        );
    }

    /**
     * "Your product launches tomorrow", for a scheduled product. The link is the maker's own page: a scheduled product is
     * not public yet. The launch date is in both keys, so a reschedule makes a new reminder and a re-run makes none.
     */
    public static function launchReminder(Product $product): NotificationDraft
    {
        $date = $product->launch_date?->toDateString();

        if ($date === null) {
            throw new \InvalidArgumentException('A launch reminder needs a launch date.');
        }

        return new NotificationDraft(
            $product->maker_id,
            NotificationType::LaunchReminder,
            'launch_reminder:'.$product->id.':'.$date,
            [
                'message_key' => NotificationType::LaunchReminder->messageKey(), 'product_id' => $product->id,
                'params' => ['product_name' => DisplayName::of($product->name)], 'href' => '/me/products/'.$product->id,
            ],
            'launch_reminder:'.$product->id.':'.$date.':'.$product->maker_id,
            ['product_id' => $product->id, 'launch_date' => $date],
        );
    }

    /**
     * The drafts of one comment: the product's maker (kind `comment`) and, for a reply, the person answered (kind
     * `reply`: `reply_to_user_id`, else the top-level author). Distinct, never the commenter; when both are the same
     * person that person gets one row of kind `reply`. The mail key's hour is the comment's own creation hour (UTC), so
     * the listener and the sweep agree on it.
     *
     * @return list<NotificationDraft>
     */
    public static function comment(string $commentId): array
    {
        $comment = Comment::query()->counted()->whereAuthorAlive()->find($commentId);
        $product = $comment === null ? null : Product::query()->visible()->find($comment->product_id);

        if ($comment === null || $product === null) {
            return [];
        }

        /** @var array<string, 'comment'|'reply'> $recipients */
        $recipients = [$product->maker_id => 'comment'];

        if ($comment->parent_id !== null) {
            $answered = $comment->reply_to_user_id ?? DB::table('comments')->where('id', $comment->parent_id)->value('user_id');
            if (is_string($answered)) {
                $recipients[$answered] = 'reply';
            }
        }

        unset($recipients[$comment->user_id]);

        $bucket = $comment->created_at->clone()->utc()->format('YmdH');
        $drafts = [];
        foreach ($recipients as $userId => $kind) {
            $drafts[] = new NotificationDraft(
                (string) $userId,
                NotificationType::NewComment,
                'comment:'.$comment->id,
                [
                    'message_key' => NotificationType::NewComment->messageKey(), 'product_id' => $product->id, 'comment_id' => $comment->id,
                    'params' => ['product_name' => DisplayName::of($product->name), 'kind' => $kind], 'href' => '/products/'.$product->slug,
                ],
                'new_comment:'.$kind.':'.$product->id.':'.$bucket.':'.$userId,
                ['product_id' => $product->id, 'kind' => $kind, 'bucket' => $bucket],
            );
        }

        return $drafts;
    }
}
