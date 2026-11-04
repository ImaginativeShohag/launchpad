<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RemoveAvatar
{
    public function handle(User $user): User
    {
        $old = DB::transaction(function () use ($user): ?string {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $old = $locked->avatar_path;
            $locked->forceFill(['avatar_path' => null])->save();
            // The caller's model may be stale (a concurrent upload committed since it was loaded).
            $user->setRawAttributes($locked->getAttributes(), true);

            return $old;
        });

        if ($old !== null) {
            try {
                Storage::disk((string) config('launchpad.avatar_disk'))->delete($old);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $user;
    }
}
