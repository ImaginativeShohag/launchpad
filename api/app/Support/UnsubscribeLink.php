<?php

namespace App\Support;

use App\Enums\EmailType;
use App\Support\Mail\LinkBase;
use Illuminate\Support\Facades\URL;

/**
 * The signed one-click unsubscribe link of an optional mail: one member, one type, no expiry (an old mail must still
 * work). The signature is relative, so the web page forwards the query string verbatim to the API.
 */
final class UnsubscribeLink
{
    /** `/api/v1/email/unsubscribe?type=…&user=…&signature=…` (query parameters sorted, as signed). */
    public static function relative(string $userId, EmailType $type): string
    {
        return URL::signedRoute('email.unsubscribe', ['user' => $userId, 'type' => $type->value], absolute: false);
    }

    /** The API URL mail clients POST to (the `List-Unsubscribe` header value). */
    public static function api(string $userId, EmailType $type): string
    {
        return LinkBase::api().self::relative($userId, $type);
    }

    /** The footer link: the React page, which POSTs the same query string to the API. */
    public static function web(string $userId, EmailType $type): string
    {
        return LinkBase::web().'/unsubscribe?'.parse_url(self::relative($userId, $type), PHP_URL_QUERY);
    }
}
