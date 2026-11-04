<?php

namespace App\Actions\Auth;

use App\Actions\Export\ExportFiles;
use App\Actions\Media\StoreImage;
use App\Enums\AuthEventType;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Exceptions\LastAdminException;
use App\Exceptions\ProductRuleViolation;
use App\Jobs\DeleteMemberComments;
use App\Jobs\VoidMemberVotes;
use App\Models\Product;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Support\AuthLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DeleteAccount
{
    public function __construct(private readonly StoreImage $images) {}

    /**
     * Anonymise in place (decision 10): the row and id stay so frozen rankings, threads, the
     * audit log and anti-cheat history (risk_score) remain valid; every piece of personal data goes.
     *
     * Products are handled here: a pending, approved, scheduled or live product blocks the deletion, drafts and
     * products with requested changes are soft-deleted (slug freed, files removed after the commit), rejected and
     * taken-down products stay as hidden records. The member's votes are voided by a queued job dispatched after the
     * commit; the member's follows (both directions) and topic follows are deleted inside the transaction below. The
     * member's comments are NOT touched here: correcting `comments_count` / `replies_count` needs other makers'
     * product rows, which must never be taken while this action holds the user row (global lock order). A second
     * queued job (DeleteMemberComments), also dispatched after the commit, wipes them and corrects the counters; until
     * it runs the counters stay inflated and the reads mask the author. The member's bell rows and mail preferences are
     * deleted and their outbox rows scrubbed inside the transaction below (see deleteNotificationData()). The member's
     * export rows are deleted there and their zips removed after the commit (see deleteExports()). Later sub-projects add
     * their clean-up there too.
     *
     * Returns false when the account was already deleted (a concurrent duplicate request): nothing
     * is changed and the caller must not audit it again.
     *
     * @throws LastAdminException when this is the only active admin
     *                            Rejected and taken-down products stay as hidden records with their content (admin history, review notes);
     *                            only the maker's identity is anonymised. Their slugs stay reserved.
     *
     * Files are removed after this method's own transaction commits; call it outside an outer transaction.
     * @throws ProductRuleViolation `has_active_product` when a product is pending, approved, scheduled or live, or `try_again` when a product appeared while the locks were being taken
     */
    public function handle(User $user): bool
    {
        $id = (string) $user->getKey();

        $result = DB::transaction(function () use ($user, $id): array {
            // Global lock order (L8): 1. this member's product rows (id order), 2. the active-admin set (id order),
            // 3. the user row. An edit of the member's own product holds a product row and then needs FOR KEY SHARE
            // on this user row (the audit foreign key), so taking the user row first would deadlock with it.
            $products = Product::query()->where('maker_id', $id)->orderBy('id')->lockForUpdate()->get();

            // Lock order must be identical for everyone or two admins deleting each other's last-admin
            // check deadlock. Admins lock the whole active-admin set (ordered by id, which includes
            // their own row) BEFORE anything else; everyone else only locks their own row. The role is
            // read unlocked first to decide which; it is re-checked on the locked row below. (If the
            // member became an admin in between, we hold only our own row: any other admin deleting
            // concurrently locks the set including our row and so waits for us, which is safe.)
            $role = DB::table('users')->where('id', $id)->value('role');

            if ($role === Role::Admin->value) {
                User::query()->where('role', Role::Admin->value)->whereNull('deleted_at')
                    ->orderBy('id')->lockForUpdate()->get(['id']);
            }

            $locked = User::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($locked->isDeleted()) {
                $user->setRawAttributes($locked->getAttributes(), true);

                return ['deleted' => false, 'avatar' => null, 'files' => [], 'exports' => []];
            }

            if (UserPolicy::isLastAdmin($locked)) {
                throw new LastAdminException;
            }

            // A product inserted after the product locks were taken (a CreateDraft holding a share lock on this user
            // row) is not in `$products`. It cannot be locked now without inverting the lock order, so refuse and
            // let the caller retry: nothing has been written yet.
            if (Product::query()->withTrashed()->where('maker_id', $id)->whereNotIn('id', $products->modelKeys())->exists()) {
                throw ProductRuleViolation::changedMeanwhile();
            }

            if ($products->contains(fn (Product $product) => in_array($product->status, ProductStatus::blockingDeletion(), true))) {
                throw ProductRuleViolation::hasActiveProduct();
            }

            $this->deleteFollows($id);

            // The address is computed once: the scrubbed outbox rows and the anonymised user row carry the same value.
            $anonymisedEmail = 'deleted+'.Str::lower($id).'@invalid';
            $this->deleteNotificationData($id, $anonymisedEmail);

            $files = $this->removeNeverPublicProducts($products);
            $exports = $this->deleteExports($id);

            $oldEmail = $locked->email;
            $avatar = $locked->avatar_path;

            $locked->links()->delete();
            $locked->socialAccounts()->delete();
            $locked->tokens()->delete();
            DB::table('sessions')->where('user_id', $id)->delete();
            DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                ->whereIn('email', array_filter([$oldEmail, $locked->pending_email]))->delete();

            $locked->forceFill([
                'name' => 'Deleted member',
                'handle' => 'deleted-'.substr(Str::lower($id), -20),
                'email' => $anonymisedEmail,
                'email_verified_at' => null,
                'password' => null,
                'headline' => null,
                'avatar_path' => null,
                'pending_email' => null,
                'pending_email_expires_at' => null,
                'remember_token' => null,
                'role' => Role::Member,
                'last_login_at' => null,
                'deleted_at' => now(),
            ])->save();

            // The caller's model may be stale; hand back the anonymised state.
            $user->setRawAttributes($locked->getAttributes(), true);

            return ['deleted' => true, 'avatar' => $avatar, 'files' => $files, 'exports' => $exports];
        });

        if (! $result['deleted']) {
            return false;
        }

        // After the commit on purpose (spec section 6): voting needs other makers' product rows, which must never be
        // taken while this action holds the member's user row. A dispatch failure must not undo the committed deletion.
        VoidMemberVotes::dispatchSafely($id);

        // Same reason and same rule: the comments need other makers' product rows. The hourly sweep is the safety net.
        try {
            DeleteMemberComments::dispatch($id);
        } catch (Throwable $e) {
            report($e);
        }

        if ($result['avatar'] !== null) {
            try {
                Storage::disk((string) config('launchpad.avatar_disk'))->delete($result['avatar']);
            } catch (Throwable $e) {
                report($e);
            }
        }

        foreach ($result['files'] as $path) {
            $this->images->discard((string) config('launchpad.product_disk'), $path);
        }

        // The member's export zips, after the commit (a failed delete is reported inside and never throws).
        foreach ($result['exports'] as $path) {
            app(ExportFiles::class)->delete($path);
        }

        AuthLog::tryRecord(AuthEventType::AccountDeleted, $id);

        return true;
    }

    /**
     * Removes what the notification system holds about the member (decision 10: every piece of personal data goes). The user
     * row is already locked `FOR UPDATE` by the caller, so `RecordNotification` (user row `FOR SHARE` first, then its inserts)
     * either finished before this ran or waits and finds a deleted member; it can never leave the old address in a new row.
     * The outbox rows are updated, never locked on their own first: a sender holds an outbox row lock without ever asking for
     * a user lock, so the order user row, then outbox rows has no cycle.
     *
     * - bell rows and preference rows are deleted;
     * - every outbox row of the member gets the anonymised address and loses its error text (it may quote the address);
     * - a waiting row (`queued` or `sending`) also becomes `suppressed` with no due time and an empty payload, so nothing
     *   is sent afterwards (the send-time check refuses a deleted member as well; this is defence in depth that also
     *   removes the data).
     */
    private function deleteNotificationData(string $id, string $anonymisedEmail): void
    {
        DB::table('notifications')->where('user_id', $id)->delete();
        DB::table('email_preferences')->where('user_id', $id)->delete();

        DB::update(
            "UPDATE email_messages SET
                to_email = ?,
                status = CASE WHEN status IN ('queued', 'sending') THEN 'suppressed' ELSE status END,
                next_attempt_at = CASE WHEN status IN ('queued', 'sending') THEN NULL ELSE next_attempt_at END,
                payload = CASE WHEN status IN ('queued', 'sending') THEN '{}'::jsonb ELSE payload END,
                last_error = CASE WHEN status IN ('queued', 'sending') THEN 'user_deleted' ELSE NULL END,
                updated_at = ?
             WHERE user_id = ?",
            [$anonymisedEmail, now('UTC')->format('Y-m-d H:i:s.uP'), $id],
        );
    }

    /**
     * Deletes the member's export rows (a leaf of the lock order: nothing waits on them while this holds the user row) and
     * returns their file keys, so the zips go after the commit. The key is derived from the ids, not read from `file_path`,
     * so a file whose row never recorded its path is removed too. A build that finishes later finds its row gone and deletes
     * its own file.
     *
     * @return list<string>
     */
    private function deleteExports(string $id): array
    {
        $rows = DB::table('exports')->where('user_id', $id)->orderBy('id')->lockForUpdate()->get(['id', 'product_id']);
        DB::table('exports')->where('user_id', $id)->delete();

        return array_values($rows->map(fn (object $row): string => $row->product_id.'/'.$row->id.'.zip')->all());
    }

    /**
     * Deletes the member's follows in both directions and their topic follows. The follow rows are locked first, in
     * one statement ordered by (follower_id, followed_id): two members who follow each other and delete their accounts
     * at the same time would otherwise delete the rows (a,b) and (b,a) in opposite orders and deadlock. Every other
     * writer of these rows takes at most one of them per statement, after a user row.
     */
    private function deleteFollows(string $id): void
    {
        DB::table('user_follows')
            ->where(fn ($q) => $q->where('follower_id', $id)->orWhere('followed_id', $id))
            ->orderBy('follower_id')->orderBy('followed_id')
            ->lockForUpdate()
            ->get(['follower_id', 'followed_id']);

        DB::table('user_follows')->where(fn ($q) => $q->where('follower_id', $id)->orWhere('followed_id', $id))->delete();
        DB::table('topic_follows')->where('user_id', $id)->delete();
    }

    /**
     * Drafts and products with requested changes were never public: soft-delete them under a freed slug (D3) and
     * return their files for deletion after the commit. Rejected and taken-down products stay as hidden records.
     *
     * @param  Collection<int, Product>  $products  locked rows
     * @return list<string>
     */
    private function removeNeverPublicProducts(Collection $products): array
    {
        $files = [];

        foreach ($products as $product) {
            if (! in_array($product->status, [ProductStatus::Draft, ProductStatus::ChangesRequested], true)) {
                continue;
            }

            foreach ($product->screenshots()->pluck('path') as $path) {
                $files[] = (string) $path;
            }
            if ($product->logo_path !== null) {
                $files[] = $product->logo_path;
            }

            $product->screenshots()->delete();
            $product->links()->delete();
            $product->topics()->detach();
            $product->forceFill(['slug' => 'deleted-'.strtolower((string) $product->getKey()), 'logo_path' => null])->save();
            $product->delete();
        }

        return $files;
    }
}
