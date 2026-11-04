<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

class HandleGenerator
{
    /** Always returns a handle matching the Handle rule: 3-30 chars of a-z, 0-9 and underscore, not reserved, not taken. */
    public function for(string $name, string $email): string
    {
        $base = $this->slug($name);

        if (strlen($base) < 3) {
            $base = $this->slug(Str::before($email, '@'));
        }

        if (strlen($base) < 3) {
            $base = 'member';
        }

        $base = substr($base, 0, 24);
        $candidate = $base;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            if (! $this->unavailable($candidate)) {
                return $candidate;
            }

            $candidate = $base.'_'.random_int(1000, 9999);
        }

        return $base.'_'.Str::lower(Str::random(5));
    }

    private function slug(string $text): string
    {
        // Invalid UTF-8 would make the regex fail; drop it first.
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return Str::of(Str::ascii($text))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }

    private function unavailable(string $handle): bool
    {
        return in_array($handle, config('launchpad.reserved_handles'), true)
            || User::query()->where('handle', $handle)->exists();
    }
}
