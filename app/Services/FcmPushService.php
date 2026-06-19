<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends push notifications via Firebase Cloud Messaging (legacy HTTP API).
 * Configure FCM_SERVER_KEY on the server (Firebase Console → Project settings → Cloud Messaging).
 */
class FcmPushService
{
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): bool
    {
        $serverKey = config('services.fcm.server_key');
        if (!$serverKey || $tokens === []) {
            return false;
        }

        $payload = [
            'registration_ids' => array_values($tokens),
            'priority' => 'high',
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
            ],
            'data' => array_map('strval', $data),
        ];

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'key='.$serverKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://fcm.googleapis.com/fcm/send', $payload);

            if (!$response->successful()) {
                Log::warning('FCM send failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $json = $response->json();
            if (isset($json['results'])) {
                foreach ($json['results'] as $index => $result) {
                    if (isset($result['error'])) {
                        Log::warning('FCM token error', [
                            'error' => $result['error'],
                            'token' => substr($tokens[$index] ?? '', 0, 12).'…',
                        ]);
                    }
                }
            }

            return ($json['success'] ?? 0) > 0;
        } catch (\Throwable $e) {
            Log::error('FCM exception: '.$e->getMessage());

            return false;
        }
    }
}
