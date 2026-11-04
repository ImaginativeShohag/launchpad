<?php

namespace App\Support;

use App\Enums\AuthEventType;
use App\Models\AuthEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuthLog
{
    public static function record(AuthEventType $event, ?string $userId = null, ?Request $request = null): AuthEvent
    {
        $request ??= request();

        return AuthEvent::query()->create([
            'user_id' => $userId,
            'event' => $event,
            'ip_hash' => DeviceHash::ip($request),
            'device_hash' => DeviceHash::device($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, '') ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Best-effort variant for bookkeeping that must never break the request: the write runs in its own
     * transaction (a savepoint when nested) so a failed statement cannot poison the caller's Postgres
     * transaction, and any failure is reported instead of thrown.
     */
    public static function tryRecord(AuthEventType $event, ?string $userId = null, ?Request $request = null): void
    {
        try {
            DB::transaction(fn () => self::record($event, $userId, $request));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
