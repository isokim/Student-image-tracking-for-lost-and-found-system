<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The database is the source of truth for notifications; Reverb only
 * pushes a live copy to an open tab. So this endpoint is what the UI
 * falls back to on page load, and it must work whether or not the
 * WebSocket connection ever succeeded.
 */
class NotificationController extends Controller
{
    /** GET /api/notifications */
    public function index(Request $request): JsonResponse
    {
        // Read off the relationship, so the query is inherently scoped
        // to the caller. There is no id parameter to tamper with.
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(NotificationResource::collection($notifications));
    }

    /** POST /api/notifications/{notification}/read */
    public function markRead(Request $request, DatabaseNotification $notification): NotificationResource
    {
        $this->authorize('markAsRead', $notification);

        $notification->markAsRead();

        return new NotificationResource($notification->fresh());
    }

    /** POST /api/notifications/read-all */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->message('All notifications marked as read.');
    }
}
