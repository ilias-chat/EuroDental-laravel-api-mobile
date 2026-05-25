<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Get notifications for the authenticated user only
        $user = auth()->user();
        
        $query = $user->notifications()->orderBy('created_at', 'desc');
        
        // Filter by read status
        if ($request->has('is_read')) {
            $isRead = filter_var($request->is_read, FILTER_VALIDATE_BOOLEAN);
            $query->where('is_read', $isRead);
        }
        
        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }
        
        // Pagination - same as clients (10 per page)
        $notifications = $query->paginate(10);
        
        // Transform the collection to clean format
        $notifications->getCollection()->transform(function ($notification) {
            return [
                'id' => $notification->id,
                'title' => $notification->title,
                'body' => $notification->body,
                'type' => $notification->type,
                'data' => $notification->data,
                'is_read' => $notification->is_read,
                'is_sent' => $notification->is_sent,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
                'updated_at' => $notification->updated_at
            ];
        });
        
        return response()->json([
            'notifications' => $notifications
        ]);
    }

    public function show($id): JsonResponse
    {
        try {
            $user = auth()->user();
            $notification = $user->notifications()->find($id);
            
            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found'
                ], 404);
            }
            
            return response()->json([
                'success' => true,
                'notification' => $notification
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch notification'
            ], 500);
        }
    }

    public function markAsRead($id): JsonResponse
    {
        try {
            $user = auth()->user();
            $notification = $user->notifications()->find($id);
            
            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found'
                ], 404);
            }
            
            $notification->markAsRead();
            
            return response()->json([
                'success' => true,
                'message' => 'Notification marked as read',
                'notification' => $notification->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notification as read'
            ], 500);
        }
    }

    public function markAllAsRead(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $user->notifications()
                ->where('is_read', false)
                ->update([
                    'is_read' => true,
                    'read_at' => now()
                ]);
            
            return response()->json([
                'success' => true,
                'message' => 'All notifications marked as read'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark notifications as read'
            ], 500);
        }
    }

    public function getUnreadCount(): JsonResponse
    {
        try {
            $user = auth()->user();
            $count = $user->notifications()->unread()->count();
            
            return response()->json([
                'success' => true,
                'unread_count' => $count
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get unread count'
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $user = auth()->user();
            $notification = $user->notifications()->find($id);
            
            if (!$notification) {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification not found'
                ], 404);
            }
            
            $notification->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Notification deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete notification'
            ], 500);
        }
    }
}
