<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notification\CountUnread;
use App\Actions\Notification\ListNotifications;
use App\Actions\Notification\MarkNotificationsRead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListNotificationsRequest;
use App\Http\Requests\Api\V1\MarkNotificationsReadRequest;
use App\Http\Resources\Api\V1\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    /** The member's own bell, newest first. */
    public function index(ListNotificationsRequest $request, ListNotifications $list): AnonymousResourceCollection
    {
        $page = $list->handle($this->authenticated($request)->getKey(), $request->unreadOnly(), $request->perPage())
            ->appends(array_filter(['per_page' => $request->perPage(), 'unread' => $request->unreadOnly() ? '1' : null]));

        return NotificationResource::collection($page);
    }

    public function unreadCount(Request $request, CountUnread $count): JsonResponse
    {
        $result = $count->handle($this->authenticated($request)->getKey());

        return response()->json(['unread_count' => $result['count'], 'capped' => $result['capped']]);
    }

    public function markRead(MarkNotificationsReadRequest $request, MarkNotificationsRead $mark, CountUnread $count): JsonResponse
    {
        $userId = $this->authenticated($request)->getKey();
        $marked = $mark->handle($userId, $request->ids(), $request->markAll());

        return response()->json(['marked' => $marked, 'unread_count' => $count->handle($userId)['count']]);
    }
}
