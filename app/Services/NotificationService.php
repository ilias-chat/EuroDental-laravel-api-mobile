<?php

namespace App\Services;

use App\Models\User;
use App\Models\Notification;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class NotificationService
{
    private string $expoApiUrl = 'https://exp.host/--/api/v2/push/send';
    private int $chunkSize = 100;

    public function sendToUser(int $userId, string $title, string $body, array $data = []): bool
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                Log::warning("User not found for notification", ['user_id' => $userId]);
                return false;
            }

            // Store notification in database first
            $notification = $this->storeNotification($userId, $title, $body, $data);

            $expoTokens = [];
            $fcmTokens = [];
            foreach ($user->pushTokens()->where('is_active', true)->get() as $pushToken) {
                $token = $pushToken->expo_push_token;
                if (str_starts_with($token, 'ExponentPushToken[')) {
                    $expoTokens[] = $token;
                } else {
                    $fcmTokens[] = $token;
                }
            }

            $sentExpo = false;
            if ($expoTokens !== []) {
                $sentExpo = $this->sendToMultipleUsers($expoTokens, $title, $body, $data);
            }

            $sentFcm = false;
            if ($fcmTokens !== []) {
                $sentFcm = app(FcmPushService::class)->sendToTokens($fcmTokens, $title, $body, $data);
            }

            $sentWeb = $this->sendWebPushToUser($userId, $title, $body, $data);

            if (($sentExpo || $sentFcm || $sentWeb) && $notification) {
                $notification->update(['is_sent' => true]);
            }

            return $sentExpo || $sentFcm || $sentWeb;
        } catch (\Exception $e) {
            Log::error("Error sending notification to user", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function sendToMultipleUsers(array $tokens, string $title, string $body, array $data = []): bool
    {
        try {
            $messages = $this->buildMessages($tokens, $title, $body, $data);
            $chunks = array_chunk($messages, $this->chunkSize);
            
            $success = true;
            foreach ($chunks as $chunk) {
                if (!$this->sendChunk($chunk)) {
                    $success = false;
                }
            }

            return $success;
        } catch (\Exception $e) {
            Log::error("Error sending notifications to multiple users", [
                'token_count' => count($tokens),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function buildMessages(array $tokens, string $title, string $body, array $data): array
    {
        $messages = [];
        foreach ($tokens as $token) {
            $messages[] = [
                'to' => $token,
                'sound' => 'default',
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'priority' => 'high',
            ];
        }
        return $messages;
    }

    private function sendWebPushToUser(int $userId, string $title, string $body, array $data = []): bool
    {
        try {
            $subs = WebPushSubscription::where('user_id', $userId)->where('is_active', true)->get();
            if ($subs->isEmpty()) {
                Log::info('No WebPush subscriptions found', ['user_id' => $userId]);
                return false;
            }

            $auth = config('services.webpush.vapid');
            if (!$auth['public_key'] || !$auth['private_key']) {
                Log::warning('WebPush VAPID not configured', $auth);
                return false;
            }

            Log::info('WebPush attempting send with GMP enabled', [
                'user_id' => $userId,
                'subscriptions_count' => $subs->count(),
            ]);

            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $auth['subject'],
                    'publicKey' => $auth['public_key'],
                    'privateKey' => $auth['private_key'],
                ],
            ]);

            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);

            $success = true;
            foreach ($subs as $s) {
                $subscription = Subscription::create([
                    'endpoint' => $s->endpoint,
                    'keys' => [
                        'p256dh' => $s->p256dh,
                        'auth' => $s->auth,
                    ],
                ]);

                $report = $webPush->sendOneNotification($subscription, $payload);
                if ($report && method_exists($report, 'isSuccess')) {
                    if ($report->isSuccess()) {
                        Log::info('WebPush sent successfully', [
                            'user_id' => $userId,
                            'endpoint' => substr($s->endpoint, -20),
                        ]);
                    } else {
                        $success = false;
                        Log::warning('WebPush send failed', [
                            'user_id' => $userId,
                            'endpoint' => substr($s->endpoint, -20),
                            'status' => method_exists($report, 'getStatusCode') ? $report->getStatusCode() : null,
                            'reason' => method_exists($report, 'getReason') ? $report->getReason() : null,
                        ]);
                        if (method_exists($report, 'isSubscriptionExpired') && $report->isSubscriptionExpired()) {
                            $s->delete();
                        }
                    }
                }
            }

            $webPush->flush();
            return $success;
        } catch (\Throwable $e) {
            Log::error('WebPush error: '.$e->getMessage());
            return false;
        }
    }

    public function sendWebPushOnly(int $userId, string $title, string $body, array $data = []): bool
    {
        // Store notification in database first
        $notification = $this->storeNotification($userId, $title, $body, $data);
        
        // Send the web push
        $sent = $this->sendWebPushToUser($userId, $title, $body, $data);
        
        // Update notification as sent if successful
        if ($sent && $notification) {
            $notification->update(['is_sent' => true]);
        }
        
        return $sent;
    }

    private function sendChunk(array $messages): bool
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Accept-encoding' => 'gzip, deflate',
                    'Content-Type' => 'application/json',
                ])
                ->post($this->expoApiUrl, $messages);

            if ($response->successful()) {
                $this->handleSuccessfulResponse($response, $messages);
                return true;
            } else {
                $this->handleFailedResponse($response, $messages);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Error sending notification chunk", [
                'error' => $e->getMessage(),
                'message_count' => count($messages)
            ]);
            return false;
        }
    }

    private function handleSuccessfulResponse(Response $response, array $messages): void
    {
        $result = $response->json();
        
        // Handle Expo's response format
        if (isset($result['data'])) {
            foreach ($result['data'] as $index => $item) {
                if (isset($item['status']) && $item['status'] === 'error') {
                    Log::warning("Expo push notification error", [
                        'token' => $messages[$index]['to'] ?? 'unknown',
                        'error' => $item['message'] ?? 'unknown error'
                    ]);
                    
                    // Mark invalid tokens as inactive
                    $this->deactivateInvalidToken($messages[$index]['to']);
                }
            }
        }
    }

    private function handleFailedResponse(Response $response, array $messages): void
    {
        Log::error("Failed to send notification chunk", [
            'status' => $response->status(),
            'body' => $response->body(),
            'message_count' => count($messages)
        ]);
    }

    private function deactivateInvalidToken(string $token): void
    {
        \App\Models\PushToken::where('expo_push_token', $token)
            ->update(['is_active' => false]);
    }

    private function storeNotification(int $userId, string $title, string $body, array $data = []): ?Notification
    {
        try {
            $notificationType = $data['type'] ?? 'general';
            
            return Notification::create([
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'type' => $notificationType,
                'is_read' => false,
                'is_sent' => false
            ]);
        } catch (\Exception $e) {
            Log::error("Error storing notification", [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
