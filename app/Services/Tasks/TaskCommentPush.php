<?php

namespace App\Services\Tasks;

use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

class TaskCommentPush
{
    public function deliver(int $id): void
    {
        try {
            $notification = Notification::find($id);
            if (! $notification || $notification->is_read) return;
            $vapid = [
                'subject' => config('services.webpush.subject') ?: config('services.webpush.vapid.subject'),
                'publicKey' => config('services.webpush.public_key') ?: config('services.webpush.vapid.public_key'),
                'privateKey' => config('services.webpush.private_key') ?: config('services.webpush.vapid.private_key'),
            ];
            if (! $vapid['publicKey'] || ! $vapid['privateKey']) return;
            $push = new WebPush(['VAPID' => $vapid], ['TTL' => 3600]);
            $seen = [];
            // A PWA endpoint may also be registered with the CRM API. Deliver only once.
            foreach (['web_push_subscriptions' => 'pwa_url', 'push_subscriptions' => 'crm_url'] as $table => $urlKey) {
                if (! Schema::hasTable($table)) continue;
                $query = DB::table($table)->where('user_id', $notification->user_id);
                if ($table === 'web_push_subscriptions') $query->where('is_active', true);
                foreach ($query->get() as $row) {
                    if (isset($seen[$row->endpoint])) continue;
                    $seen[$row->endpoint] = true;
                    $url = $notification->data[$urlKey];
                    $payload = [
                        'title' => $notification->title, 'body' => $notification->body,
                        'type' => 'task_comment', 'notification_id' => $notification->id,
                        'tag' => 'task-comments-' . $notification->data['task_id'],
                        'url' => $url, 'data' => $notification->data + ['url' => $url],
                    ];
                    $push->queueNotification(Subscription::create([
                        'endpoint' => $row->endpoint,
                        'publicKey' => $row->p256dh ?? $row->public_key,
                        'authToken' => $row->auth ?? $row->auth_token,
                        'contentEncoding' => $row->content_encoding ?: 'aes128gcm',
                    ]), json_encode($payload, JSON_THROW_ON_ERROR));
                }
            }
            $sent = false;
            foreach ($push->flush() as $report) {
                $sent = $sent || $report->isSuccess();
                if ($report->isSubscriptionExpired()) {
                    $endpoint = (string) $report->getRequest()->getUri();
                    foreach (['web_push_subscriptions', 'push_subscriptions'] as $table) {
                        if (Schema::hasTable($table)) DB::table($table)->where('user_id', $notification->user_id)->where('endpoint', $endpoint)->delete();
                    }
                }
                if (! $report->isSuccess()) Log::warning('Task comment push delivery failed.', ['notification_id' => $id, 'reason' => $report->getReason()]);
            }
            if ($sent) Notification::whereKey($id)->where('data->last_comment_id', $notification->data['last_comment_id'])->update(['is_sent' => true]);
        } catch (Throwable $exception) {
            // Push is best effort; the comment and notification inbox remain available.
            Log::warning('Task comment push unavailable.', ['notification_id' => $id, 'error' => $exception->getMessage()]);
        }
    }
}
