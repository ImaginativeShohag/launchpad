<?php

namespace App\Mail;

use App\Enums\EmailType;
use App\Enums\ProductStatus;
use App\Models\EmailMessage;
use App\Models\Product;
use App\Support\Mail\DisplayName;
use App\Support\Mail\LinkBase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the mail of one outbox row from its type and payload (ids and small scalars). The product name is re-read
 * here, so it is current and goes through the display-name cleaner; nothing from another person (a comment body, a
 * review note, an address) is ever read. Links are `web_url` plus a path built from ids and slugs, never a stored URL.
 */
final class MailFor
{
    /**
     * @throws UnrenderableMail an unknown or mandatory type, a missing key, a mistyped value
     * @throws MailTargetGone the product is missing, soft-deleted or no longer visible
     */
    public static function make(EmailMessage $message): Mailable
    {
        $payload = $message->payload;
        $userId = $message->user_id;

        return match ($message->type) {
            EmailType::ReviewOutcome => self::reviewOutcome($userId, $payload),
            EmailType::LaunchReminder => self::launchReminder($userId, $payload),
            EmailType::ProductLive => self::productLive($userId, $payload),
            EmailType::NewComment => self::newComment($userId, $payload),
            EmailType::DailyTop5 => self::dailyTop5($userId, $payload),
            EmailType::WeeklyBest => self::weeklyBest($userId, $payload),
            EmailType::ExportReady => self::exportReady($userId, $payload),
            default => throw new UnrenderableMail("{$message->type->value} is not an outbox mail."),
        };
    }

    public static function webUrl(string $path): string
    {
        return LinkBase::web().$path;
    }

    /** @param  array<string, mixed>  $payload */
    private static function reviewOutcome(string $userId, array $payload): Mailable
    {
        self::shape($payload, ['review_id', 'product_id', 'decision']);
        $decision = self::oneOf($payload['decision'], ['approved', 'rejected', 'changes_requested']);
        self::id($payload['review_id']);
        $product = self::existingProduct(self::id($payload['product_id']));

        return new ReviewOutcomeMail($userId, $product->id, DisplayName::of($product->name), $decision);
    }

    /** @param  array<string, mixed>  $payload */
    private static function launchReminder(string $userId, array $payload): Mailable
    {
        self::shape($payload, ['product_id', 'launch_date']);
        $date = self::date($payload['launch_date']);
        $product = self::existingProduct(self::id($payload['product_id']));

        // A reminder is only true while the product is still the recipient's, still scheduled, and still launches that day.
        if ($product->maker_id !== $userId || $product->status !== ProductStatus::Scheduled || $product->launch_date?->toDateString() !== $date) {
            throw new MailTargetGone('The launch is no longer scheduled for that day.');
        }

        return new LaunchReminderMail($userId, $product->id, DisplayName::of($product->name), $date);
    }

    /** @param  array<string, mixed>  $payload */
    private static function productLive(string $userId, array $payload): Mailable
    {
        self::shape($payload, ['product_id', 'launch_date']);
        self::date($payload['launch_date']);
        $product = self::publicProduct(self::id($payload['product_id']));

        return new ProductLiveMail($userId, $product->slug, DisplayName::of($product->name));
    }

    /** @param  array<string, mixed>  $payload */
    private static function newComment(string $userId, array $payload): Mailable
    {
        self::shape($payload, ['product_id', 'kind', 'bucket']);
        $kind = self::oneOf($payload['kind'], ['comment', 'reply']);
        if (! is_string($payload['bucket']) || preg_match('/\A\d{10}\z/', $payload['bucket']) !== 1) {
            throw new UnrenderableMail('The bucket must be YmdH.');
        }
        $product = self::publicProduct(self::id($payload['product_id']));

        return new NewCommentMail($userId, $product->slug, DisplayName::of($product->name), $kind);
    }

    /** @param  array<string, mixed>  $payload */
    private static function exportReady(string $userId, array $payload): Mailable
    {
        self::shape($payload, ['export_id', 'product_id']);
        $exportId = self::id($payload['export_id']);
        $product = self::existingProduct(self::id($payload['product_id']));

        // The mail is only true while the export is still downloadable and still this member's.
        if (! DB::table('exports')->where('id', $exportId)->where('user_id', $userId)->where('status', 'ready')->exists()) {
            throw new MailTargetGone('The export is no longer ready.');
        }

        return new ExportReadyMail($userId, $product->id, DisplayName::of($product->name));
    }

    /** @param  array<string, mixed>  $payload */
    private static function dailyTop5(string $userId, array $payload): Mailable
    {
        self::shape($payload, ['date', 'product_ids']);
        $date = self::date($payload['date']);

        return new DailyTop5Mail($userId, $date, self::digestProducts($payload['product_ids']));
    }

    /** @param  array<string, mixed>  $payload */
    private static function weeklyBest(string $userId, array $payload): Mailable
    {
        self::shape($payload, ['week_start', 'product_ids']);
        $weekStart = self::date($payload['week_start']);

        return new WeeklyBestMail($userId, $weekStart, self::digestProducts($payload['product_ids']));
    }

    /**
     * The payload's products that are still publicly visible, in the payload's order.
     *
     * @return list<array{name: string, url: string}>
     *
     * @throws MailTargetGone when none is left
     */
    private static function digestProducts(mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || $ids === [] || count($ids) > 5) {
            throw new UnrenderableMail('product_ids must be a list of one to five ids.');
        }
        $ids = array_map(fn (mixed $id) => self::id($id), $ids);

        $found = Product::query()->visible()->whereMakerAvailable()->whereIn('id', $ids)->get()->keyBy('id');
        $products = [];
        foreach ($ids as $id) {
            $product = $found->get($id);
            if ($product !== null) {
                $products[] = ['name' => DisplayName::of($product->name), 'url' => self::webUrl('/products/'.$product->slug)];
            }
        }

        if ($products === []) {
            throw new MailTargetGone('No product of the digest is visible any more.');
        }

        return $products;
    }

    /** Any existing, not soft-deleted product (it does not check the owner; the reminder path does). */
    private static function existingProduct(string $id): Product
    {
        return Product::query()->find($id) ?? throw new MailTargetGone('The product is gone.');
    }

    private static function publicProduct(string $id): Product
    {
        return Product::query()->visible()->whereMakerAvailable()->find($id) ?? throw new MailTargetGone('The product is not visible.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function shape(array $payload, array $keys): void
    {
        $actual = array_keys($payload);
        sort($actual);
        sort($keys);

        if ($actual !== $keys) {
            throw new UnrenderableMail('The payload keys are not the documented ones.');
        }
    }

    private static function id(mixed $value): string
    {
        if (! is_string($value) || ! Str::isUlid($value)) {
            throw new UnrenderableMail('An id in the payload is not a ULID.');
        }

        return $value;
    }

    private static function date(mixed $value): string
    {
        if (! is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new UnrenderableMail('A date in the payload is not Y-m-d.');
        }

        return $value;
    }

    /** @param  list<string>  $allowed */
    private static function oneOf(mixed $value, array $allowed): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new UnrenderableMail('A payload value is not one of the allowed ones.');
        }

        return $value;
    }
}
