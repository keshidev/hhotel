<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user.
     * Ordered by newest first.
     */
    public function index(Request $request)
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'filter' => ['sometimes', 'in:all,unread'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:1024'],
        ]);
        $limit = max(1, min(50, (int) $request->input('limit', 20)));
        $cursor = $request->input('cursor');

        $query = Notification::where('user_id', $userId)
            ->when($request->input('filter') === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($cursor) {
            $decoded = json_decode(base64_decode((string) $cursor, true) ?: '', true);
            if (! is_array($decoded)) {
                return response()->json(['message' => 'Invalid notification cursor.'], 422);
            }
            $createdAt = $decoded['created_at'] ?? null;
            $id = isset($decoded['id']) ? (int) $decoded['id'] : null;

            if (! is_string($createdAt) || ! $id || $id < 1) {
                return response()->json(['message' => 'Invalid notification cursor.'], 422);
            }
            try {
                $createdAt = Carbon::parse($createdAt)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
            } catch (\Throwable $exception) {
                return response()->json(['message' => 'Invalid notification cursor.'], 422);
            }
            if ($createdAt && $id) {
                $query->where(function ($builder) use ($createdAt, $id) {
                    $builder->where('created_at', '<', $createdAt)
                        ->orWhere(function ($sameMoment) use ($createdAt, $id) {
                            $sameMoment->where('created_at', $createdAt)
                                ->where('id', '<', $id);
                        });
                });
            }
        }

        $notifications = $query->limit($limit + 1)->get();
        $hasMore = $notifications->count() > $limit;
        $items = $notifications->take($limit)->values();
        $last = $items->last();

        return response()->json([
            'data' => $items,
            'unread_count' => Notification::where('user_id', $userId)
                ->whereNull('read_at')
                ->count(),
            'next_cursor' => $hasMore && $last
                ? base64_encode(json_encode([
                    'created_at' => optional($last->created_at)->toISOString(),
                    'id' => $last->id,
                ]))
                : null,
            'total_count' => Notification::where('user_id', $userId)
                ->when($request->input('filter') === 'unread', fn ($q) => $q->whereNull('read_at'))
                ->count(),
        ]);
    }

    /**
     * Get the unread notification count for the authenticated user.
     */
    public function unreadCount(Request $request)
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $count = Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, $id)
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $notification = Notification::where('user_id', $userId)
            ->findOrFail($id);

        $notification->update(['read_at' => Carbon::now()]);

        return response()->json([
            'message' => 'Notification marked as read.',
            'notification' => $notification,
        ]);
    }

    /**
     * Mark all unread notifications as read for the authenticated user.
     */
    public function markAllAsRead(Request $request)
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        Notification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => Carbon::now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, $id)
    {
        $userId = $request->user()?->id;
        if (! $userId) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $notification = Notification::where('user_id', $userId)
            ->findOrFail($id);

        $notification->delete();

        return response()->json(['message' => 'Notification deleted.']);
    }
}
