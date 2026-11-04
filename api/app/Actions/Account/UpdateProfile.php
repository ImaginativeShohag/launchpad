<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateProfile
{
    /**
     * @param  array<string, mixed>  $data  validated input; `links`, when present, replaces the whole list
     */
    public function handle(User $user, array $data): User
    {
        try {
            return DB::transaction(function () use ($user, $data): User {
                // Lock the member's row first: even when nothing on it changes (links only), concurrent
                // updates for the same member are serialised and cannot race on unique(user_id, position).
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $locked->fill(Arr::only($data, ['name', 'handle', 'headline']))->save();

                if (array_key_exists('links', $data)) {
                    // unique(user_id, position) is not deferrable: delete everything, then insert 0..n-1.
                    $locked->links()->delete();

                    foreach (array_values((array) $data['links']) as $position => $link) {
                        $locked->links()->create([
                            'kind' => $link['kind'],
                            'label' => $link['label'] ?? null,
                            'url' => $link['url'],
                            'position' => $position,
                        ]);
                    }
                }

                $user->setRawAttributes($locked->getAttributes(), true);

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Only a handle clash is the member's mistake; any other unique violation is a real fault.
            if ($this->handleTakenByAnother($user, $data)) {
                throw ValidationException::withMessages(['handle' => ['This handle is already taken.']]);
            }

            throw $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function handleTakenByAnother(User $user, array $data): bool
    {
        if (! isset($data['handle']) || ! is_string($data['handle'])) {
            return false;
        }

        return User::query()
            ->where('handle', Str::lower(trim($data['handle'])))
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}
