<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class Audit
{
    /**
     * `meta` is returned as stored by the admin audit-log API: never put emails, names, tokens or any
     * other personal data in it (ids, enum values and short reasons only).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function record(
        string $action,
        ?User $actor = null,
        ?Model $subject = null,
        array $meta = [],
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        return AuditLog::query()->create([
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta,
            'ip_hash' => DeviceHash::ip($request),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, '') ?: null,
            'created_at' => now(),
        ]);
    }
}
