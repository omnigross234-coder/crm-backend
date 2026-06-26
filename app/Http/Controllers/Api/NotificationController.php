<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Notifications retrieved.',
            'data' => [
                'unread_count' => $request->user()->unreadNotifications()->count(),
                'notifications' => $notifications,
            ],
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();

        if (! $notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
                'data' => null,
            ], 404);
        }

        $this->markRelatedFollowupNotificationsAsRead($notification);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
            'data' => $notification->fresh(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
            'data' => null,
        ]);
    }

    private function markRelatedFollowupNotificationsAsRead(DatabaseNotification $notification): void
    {
        $followupId = data_get($notification->data, 'followup_id');

        if (! $followupId) {
            $notification->markAsRead();

            return;
        }

        DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('type', $notification->type)
            ->where('data->type', 'followup_reminder')
            ->where('data->followup_id', $followupId)
            ->update(['read_at' => now()]);
    }
}
