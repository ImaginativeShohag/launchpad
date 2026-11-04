<?php

namespace App\Enums;

enum Role: string
{
    case Member = 'member';
    case Reviewer = 'reviewer';
    case Admin = 'admin';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }

    public function isStaff(): bool
    {
        return $this !== self::Member;
    }
}
