<?php

namespace App\Actions\Admin;

use App\Enums\Role;
use App\Models\User;
use App\Support\LikePattern;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * The admin member directory, newest first (`created_at DESC, id DESC`, served by users_created_at_id_index while deleted
 * accounts are left out). `q` matches the start of the handle or of the name or of any word in it; wildcards in the text are
 * escaped and the pattern is a bound parameter. The e-mail is never searched.
 */
final class MemberDirectory
{
    /** @return CursorPaginator<int, User> */
    public function page(?string $q, ?Role $role, ?string $status, int $perPage): CursorPaginator
    {
        return User::query()
            ->when($status === 'deleted', fn ($query) => $query->whereNotNull('deleted_at'), fn ($query) => $query->whereNull('deleted_at'))
            ->when($status === 'active', fn ($query) => $query->whereNull('suspended_at'))
            ->when($status === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
            ->when($role !== null, fn ($query) => $query->where('role', $role?->value))
            ->when($q !== null, function ($query) use ($q): void {
                $prefix = LikePattern::prefix((string) $q);
                $word = '% '.$prefix;
                $query->where(fn ($match) => $match
                    ->whereRaw("lower(handle) like ? escape '\\'", [$prefix])
                    ->orWhereRaw("lower(name) like ? escape '\\'", [$prefix])
                    ->orWhereRaw("lower(name) like ? escape '\\'", [$word]));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }
}
