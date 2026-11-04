<?php

namespace App\Enums;

enum AuthEventType: string
{
    case Signup = 'signup';
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case GoogleLinked = 'google_linked';
    case EmailVerified = 'email_verified';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case TokenCreated = 'token_created';
    case TokenRevoked = 'token_revoked';
    case AccountDeleted = 'account_deleted';
    case EmailChangeRequested = 'email_change_requested';
    case EmailChanged = 'email_changed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $event) => $event->value, self::cases());
    }
}
