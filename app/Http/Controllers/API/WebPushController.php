<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WebPushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebPushController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        $request->validate([
            'endpoint' => 'required|url',
            'keys.p256dh' => 'required|string',
            'keys.auth' => 'required|string',
            'contentEncoding' => 'nullable|string',
        ]);

        $user = $request->user();

        WebPushSubscription::updateOrCreate(
            ['endpoint' => $request->endpoint],
            [
                'user_id' => $user->id,
                'p256dh' => $request->input('keys.p256dh'),
                'auth' => $request->input('keys.auth'),
                'content_encoding' => $request->input('contentEncoding'),
                'is_active' => true,
            ]
        );

        return response()->json(['success' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => 'required|url']);

        WebPushSubscription::where('endpoint', $request->endpoint)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['success' => true]);
    }
}
