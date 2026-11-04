<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AuditLogIndexRequest;
use App\Http\Resources\Api\V1\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class AuditLogController extends Controller
{
    public function __invoke(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        // Keyset (cursor) pagination: no COUNT(*) over an ever-growing table, and a hard page-size cap.
        $logs = AuditLog::query()
            ->with('actor')
            ->when($filters['actor'] ?? null, fn ($query, $actor) => $query->where('actor_id', $actor))
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate((int) ($filters['per_page'] ?? AuditLogIndexRequest::DEFAULT_PER_PAGE));

        return AuditLogResource::collection($logs);
    }
}
