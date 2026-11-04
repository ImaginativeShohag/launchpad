<?php

namespace App\Support;

/** Which user agents are never counted as a page view: none at all, or one that names a crawler, tool or link previewer. */
final class BotAgents
{
    public static function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return true;
        }

        $agent = strtolower($userAgent);

        foreach ((array) config('launchpad.views.bot_agents', []) as $needle) {
            if (! is_string($needle) || $needle === '') {
                continue;
            }
            if ($needle === 'bot') {
                // A whole word, or `bot`/`Bot` closing a word that starts in lower case (Googlebot, AhrefsBot); case-sensitive
                // on the original so an all-caps brand (CUBOT) is not a crawler.
                if (preg_match('/\bbot\b/i', $userAgent) === 1 || preg_match('/(?<=[a-z])[Bb]ot\b/', $userAgent) === 1) {
                    return true;
                }

                continue;
            }
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        return false;
    }
}
