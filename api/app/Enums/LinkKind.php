<?php

namespace App\Enums;

enum LinkKind: string
{
    case Website = 'website';
    case AppStore = 'app_store';
    case PlayStore = 'play_store';
    case Github = 'github';
    case X = 'x';
    case Linkedin = 'linkedin';
    case Docs = 'docs';
    case Youtube = 'youtube';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }

    /**
     * Extra product links may be any kind except `website`: the main website is `products.website_url`.
     *
     * @return list<string>
     */
    public static function productKinds(): array
    {
        return array_values(array_filter(self::values(), fn (string $kind) => $kind !== self::Website->value));
    }
}
