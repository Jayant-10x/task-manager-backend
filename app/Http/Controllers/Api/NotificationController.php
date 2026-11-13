<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller {
    /**
     * Get logged-in user notifications
     */
    public function index (): JsonResponse {
        // Replace with your helper or standard Auth::id()
        $userId = get_logged_in_user_id();

        $notificationQuery = Notification::query()->select('not_id', 'not_kind', 'not_title', 'not_body', 'not_priority', 'not_meta_info', 'not_actor', 'not_is_read', 'not_updated_on');

        if (!is_admin()) {
            $notificationQuery->where('not_usr_id', $userId);
        }
        $notifications = $notificationQuery->orderByDesc('not_updated_on')->get();

        $unread_count = $notifications->where('not_is_read', false)->count();
        return response()->json([
            'status' => 'success',
            'notifications' => $notifications,
            'unread_count' => $unread_count
        ]);
    }

    /**
     * Handle notification click event
     * Marks as read and provides optional redirect routing data
     */
    public function click ($id): JsonResponse {
        $userId = get_logged_in_user_id();

        // Secure query ensuring users can only interact with their own notifications
        $notificationQuery = Notification::query()->where('not_id', $id);
        if (!is_admin()) {
            $notificationQuery->where('not_usr_id', $userId);
        }
        $notification = $notificationQuery->firstOrFail();

        // 1. Mark as read if not already done
        if (!$notification->not_is_read) {
            $notification->not_is_read = true;

            // If you use standard columns or custom update helpers:
            if (function_exists('setCreatedUpdatedBy')) {
                $notification->not_updated_by = setCreatedUpdatedBy();
                $notification->not_updated_on = date(config('constants.DB_DATE_TIME_FORMAT'));
            }

            $notification->save();
        }

        // 2. Extract redirection rules out of meta_info
        $meta = $notification->not_meta_info;
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?? [];
        }
        $redirectUrl = null;

        if (!empty($meta) && isset($meta['redirect_to'])) {
            $redirectUrl = $meta['redirect_to'];
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Notification processed.',
            'redirect_to' => $redirectUrl // Returns string URL/route or null
        ]);
    }

    public function markAllAsRead (): JsonResponse {
        $userId = get_logged_in_user_id();

        // Query unread notifications belonging strictly to the current user
        $query = Notification::query()
            ->where('not_is_read', false);
        if (!is_admin()) {
            $query->where('not_usr_id', $userId);
        }
        // Get count before updating
        $unreadCount = $query->count();

        if ($unreadCount > 0) {
            $updateData = [
                'not_is_read' => true,
            ];

            // Apply custom updated_by / updated_on columns if your helper function exists
            if (function_exists('setCreatedUpdatedBy')) {
                $updateData['not_updated_by'] = setCreatedUpdatedBy();
                $updateData['not_updated_on'] = date(config('constants.DB_DATE_TIME_FORMAT'));
            }

            $query->update($updateData);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'All notifications marked as read.',
            'data' => [
                'updated_count' => $unreadCount,
            ],
        ]);
    }
}
