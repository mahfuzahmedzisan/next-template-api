<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DatabaseNotificationResource;
use App\Support\Query\BuildsApiListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The current user's own notification inbox (bell + full list). Only admins
 * receive anything today — every notification this app sends is an admin
 * alert — but the endpoints themselves are scoped to "my notifications",
 * not "admin notifications", so they keep working unchanged if a future
 * phase starts notifying non-admins too.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Not routed through BuildsApiListQuery/spatie QueryBuilder: this is
        // the user's own notifications relation (a MorphMany), not a bare
        // Eloquent builder, and it needs no filters beyond "mine".
        $page = $request->user()->notifications()
            ->latest()
            ->paginate(BuildsApiListQuery::perPage($request, 20, 50))
            ->withQueryString();

        return $this->apiSuccess('Notifications retrieved successfully.', [
            'notifications' => DatabaseNotificationResource::collection($page->items())->resolve(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->apiSuccess('Unread notification count retrieved successfully.', [
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification): JsonResponse
    {
        if ($notification->notifiable_id !== $request->user()->id || $notification->notifiable_type !== $request->user()->getMorphClass()) {
            return $this->apiUnprocessable('Notification not found.');
        }

        $notification->markAsRead();

        return $this->apiSuccess('Notification marked as read.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->apiSuccess('All notifications marked as read.');
    }

    public function destroy(Request $request, DatabaseNotification $notification): JsonResponse
    {
        if ($notification->notifiable_id !== $request->user()->id || $notification->notifiable_type !== $request->user()->getMorphClass()) {
            return $this->apiUnprocessable('Notification not found.');
        }

        $notification->delete();

        return $this->apiSuccess('Notification deleted.');
    }
}
