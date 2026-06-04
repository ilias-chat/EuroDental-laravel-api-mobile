<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class PushTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string|min:10',
            'platform' => 'string|in:expo,ios,android'
        ]);

        try {
            $user = auth()->user();
            
            $pushToken = $user->pushTokens()->updateOrCreate(
                ['expo_push_token' => $request->token],
                [
                    'platform' => $request->platform ?? 'expo',
                    'is_active' => true,
                    'last_used_at' => now()
                ]
            );

            Log::info("Push token saved/updated", [
                'user_id' => $user->id,
                'token' => $request->token
            ]);

            return response()->json([
                'message' => 'Push token saved successfully',
                'token_id' => $pushToken->id
            ]);
        } catch (\Exception $e) {
            Log::error("Error saving push token", [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to save push token'
            ], 500);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        try {
            $user = auth()->user();
            $deleted = $user->pushTokens()
                ->where('expo_push_token', $request->token)
                ->delete();

            if ($deleted) {
                return response()->json(['message' => 'Push token removed successfully']);
            }

            return response()->json(['message' => 'Token not found'], 404);
        } catch (\Exception $e) {
            Log::error("Error removing push token", [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Failed to remove push token'
            ], 500);
        }
    }
}
