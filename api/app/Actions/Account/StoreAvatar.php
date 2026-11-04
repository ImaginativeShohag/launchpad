<?php

namespace App\Actions\Account;

use App\Actions\Media\StoreImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

class StoreAvatar
{
    public function __construct(private readonly StoreImage $images) {}

    /** Crop to a 512px square, re-encode as WebP, store under a random name, then swap the column. */
    public function handle(User $user, UploadedFile $file): User
    {
        $disk = (string) config('launchpad.avatar_disk');
        $stored = $this->images->store($file, $disk, 'avatars', 'avatar', square: 512);

        try {
            $old = DB::transaction(function () use ($user, $stored): ?string {
                // Lock the row so two concurrent uploads each see (and later delete) the right previous file.
                $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $old = $locked->avatar_path;
                $user->forceFill(['avatar_path' => $stored->path])->save();

                return $old;
            });
        } catch (Throwable $e) {
            $this->images->discard($disk, $stored->path);

            throw $e;
        }

        // Only after the new file is safely referenced; failing to delete the old one is not fatal.
        $this->images->discard($disk, $old);

        return $user;
    }
}
