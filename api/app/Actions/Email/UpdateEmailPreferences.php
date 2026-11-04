<?php

namespace App\Actions\Email;

use App\Actions\Email\Concerns\UpsertsEmailPreference;
use App\Enums\EmailType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Switches optional mail types on or off for the signed-in member. Preferences only gate EMAIL: the bell is unaffected.
 * The actor row is read `FOR SHARE` (a concurrent account deletion finishes first); no other lock is taken.
 */
class UpdateEmailPreferences
{
    use UpsertsEmailPreference;

    /**
     * @param  array<string, mixed>  $changes  EmailType value => bool (checked here); a partial update
     * @return list<array{type: string, enabled: bool}> the full list in `EmailType::preferenceTypes()` order
     *
     * @throws AuthorizationException when the member was suspended or deleted since the request was authorised
     */
    public function handle(User $actor, array $changes): array
    {
        return DB::transaction(function () use ($actor, $changes): array {
            $stored = DB::table('users')->where('id', $actor->getKey())->sharedLock()->first(['id', 'deleted_at', 'suspended_at']);

            if ($stored === null || $stored->deleted_at !== null || $stored->suspended_at !== null) {
                throw new AuthorizationException('This account is unavailable.');
            }

            $now = Carbon::now('UTC')->format('Y-m-d H:i:s.uP');
            foreach (EmailType::preferenceTypes() as $type) {
                if (! array_key_exists($type->value, $changes)) {
                    continue;
                }
                if (! is_bool($changes[$type->value])) {
                    throw new InvalidArgumentException('A preference value must be a boolean.');
                }
                $this->upsertPreference($actor->getKey(), $type, $changes[$type->value], $now);
            }

            return $this->list($actor->getKey());
        });
    }

    /** @return list<array{type: string, enabled: bool}> a missing row reads as on */
    public function list(string $userId): array
    {
        $stored = DB::table('email_preferences')->where('user_id', $userId)->pluck('enabled', 'type');

        return array_map(
            fn (EmailType $type) => ['type' => $type->value, 'enabled' => (bool) ($stored[$type->value] ?? true)],
            EmailType::preferenceTypes(),
        );
    }
}
