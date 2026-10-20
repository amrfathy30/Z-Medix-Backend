<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\NotificationResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponse;

    /**
     * Get paginated notifications for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'filter' => ['nullable', 'in:all,unread'],
            'category' => ['nullable', 'string', 'max:50'],
            'unread_only' => ['nullable', 'in:1,0,true,false'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $request->user()->notifications();

        if ($request->query('filter') === 'unread' || $request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        if ($category = $request->query('category')) {
            $query->where('data->category', $category);
        }

        $notifications = $query->latest()->paginate((int) $request->integer('per_page', 15))->withQueryString();

        return $this->paginatedResponse(NotificationResource::collection($notifications));
    }

    /**
     * Get unread notifications count.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->successResponse([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $model = $request->user()->notifications()->find($notification);

        if ($model === null) {
            return $this->errorResponse(__('notifications.not_found'), [], 404);
        }

        if ($model->read_at === null) {
            $model->markAsRead();
        }

        return $this->successResponse(new NotificationResource($model->fresh()), __('notifications.marked_read'));
    }

    /**
     * Mark all notifications as read for the authenticated user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->successResponse(null, __('notifications.all_marked_read'));
    }
}
