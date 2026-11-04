<?php

namespace App\Actions\Auth;

use App\Enums\AuthEventType;
use App\Exceptions\AccountUnavailable;
use App\Exceptions\GoogleEmailNotVerified;
use App\Exceptions\GoogleIdentityMismatch;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\AuthLog;
use App\Support\Google\GoogleIdentity;
use App\Support\HandleGenerator;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoginWithGoogle
{
    /** A lost first-sign-in race is resolved by running the lookup again, which now sees the winner. */
    private const MAX_ATTEMPTS = 3;

    public function __construct(private readonly HandleGenerator $handles) {}

    /**
     * 1. Known Google identity → that member.
     * 2. Same email as an existing member → link, only if Google says the email is verified.
     * 3. Unknown email → create a confirmed, password-less member (again only if verified).
     *
     * @throws GoogleEmailNotVerified
     * @throws GoogleIdentityMismatch
     * @throws AccountUnavailable
     */
    public function handle(GoogleIdentity $identity): User
    {
        for ($attempt = 1; ; $attempt++) {
            /** @var list<Closure(): mixed> $afterCommit */
            $afterCommit = [];

            try {
                $user = DB::transaction(function () use ($identity, &$afterCommit): User {
                    return $this->resolve($identity, $afterCommit);
                });
            } catch (UniqueConstraintViolationException $e) {
                // Another request created the same user or social account first. The transaction has
                // rolled back, so look again: the winner's rows are now visible (no message parsing).
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }

                continue;
            }

            // Events and audit rows are bookkeeping: they run after commit and never undo the sign-in.
            foreach ($afterCommit as $callback) {
                $callback();
            }

            return $user;
        }
    }

    /** @param list<Closure(): mixed> $afterCommit */
    private function resolve(GoogleIdentity $identity, array &$afterCommit): User
    {
        $linked = SocialAccount::query()->with('user')
            ->where('provider', 'google')
            ->where('provider_user_id', $identity->sub)
            ->first();

        if ($linked !== null) {
            return $this->available($linked->user);
        }

        if (! $identity->emailVerified) {
            throw new GoogleEmailNotVerified;
        }

        $email = (string) User::normaliseEmail($identity->email);
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            $this->available($existing);

            // Never bind a second Google account to a member who already has one: an address that was
            // recycled or reassigned must not take over the account of its previous owner.
            $otherGoogle = SocialAccount::query()
                ->where('user_id', $existing->getKey())
                ->where('provider', 'google')
                ->where('provider_user_id', '!=', $identity->sub)
                ->exists();

            if ($otherGoogle) {
                throw new GoogleIdentityMismatch;
            }
        }

        return $existing !== null
            ? $this->link($existing, $identity, $afterCommit)
            : $this->create($identity, $email, $afterCommit);
    }

    private function available(?User $user): User
    {
        if ($user === null || $user->isSuspended() || $user->isDeleted()) {
            throw new AccountUnavailable;
        }

        return $user;
    }

    /** @param list<Closure(): mixed> $afterCommit */
    private function link(User $user, GoogleIdentity $identity, array &$afterCommit): User
    {
        $wasVerified = $user->hasVerifiedEmail();

        if (! $wasVerified) {
            // Nobody proved they own this address before Google did, so whoever created the account
            // (possibly an attacker who registered the victim's email) must not keep any way in.
            // The account becomes Google-only.
            $user->forceFill([
                'email_verified_at' => now(),
                'password' => null,
                'pending_email' => null,
                'pending_email_expires_at' => null,
            ])->save();
            (new SignOutEverywhere)->handle($user);
            SocialAccount::query()->where('user_id', $user->getKey())->delete();
            DB::table((string) config('auth.passwords.users.table', 'password_reset_tokens'))
                ->where('email', $user->email)->delete();

            $afterCommit[] = fn () => event(new Verified($user));
        }

        SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'google',
            'provider_user_id' => $identity->sub,
            'provider_email' => User::normaliseEmail($identity->email),
        ]);

        $id = (string) $user->getKey();
        $afterCommit[] = fn () => AuthLog::tryRecord(AuthEventType::GoogleLinked, $id);

        return $user;
    }

    /** @param list<Closure(): mixed> $afterCommit */
    private function create(GoogleIdentity $identity, string $email, array &$afterCommit): User
    {
        $user = new User([
            'handle' => $this->handles->for($identity->name, $email),
            'name' => Str::limit(trim($identity->name) !== '' ? trim($identity->name) : Str::before($email, '@'), 80, ''),
            'email' => $email,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        SocialAccount::query()->create([
            'user_id' => $user->getKey(),
            'provider' => 'google',
            'provider_user_id' => $identity->sub,
            'provider_email' => $email,
        ]);

        // Reload so DB-default columns (role, risk_score, ...) are present under strict mode.
        $user->refresh();

        // The email is already confirmed, so the framework listener sends no mail.
        $afterCommit[] = fn () => event(new Registered($user));
        $afterCommit[] = fn () => AuthLog::tryRecord(AuthEventType::GoogleLinked, (string) $user->getKey());

        return $user;
    }
}
