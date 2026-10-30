<?php

namespace App\Services\Tasks;

use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TaskCommentNotifications
{
    // Called while the task row is locked: concurrent comments share one unread item.
    public function record(Task $task, TaskComment $comment, User $author): void
    {
        $ids = collect([$task->technician_id, ...($task->helping_user_ids ?? [])])
            ->map(fn ($id) => (int) $id)->filter()->unique()->reject(fn ($id) => $id === (int) $author->id);
        $name = trim($author->first_name . ' ' . $author->last_name);
        foreach (User::whereIn('id', $ids)->get() as $recipient) {
            $notification = $this->unread($recipient, $task)->lockForUpdate()->first();
            $count = (int) ($notification?->data['comment_count'] ?? 0) + 1;
            $values = [
                'title' => $count === 1 ? 'Nouveau commentaire' : "$count nouveaux commentaires",
                'body' => "$name a commente la tache " . ($task->reference ?: $task->task_name) . '.',
                'data' => [
                    'task_id' => $task->id, 'last_comment_id' => $comment->id, 'comment_count' => $count,
                    'crm_url' => "/taches?task={$task->id}&tab=comments",
                    'pwa_url' => "/tasks/{$task->id}?tab=comments",
                ],
                'is_sent' => false,
            ];
            if ($notification) {
                $notification->update($values);
            } else {
                $notification = Notification::create($values + ['user_id' => $recipient->id, 'type' => 'task_comment', 'is_read' => false]);
            }
            $id = $notification->id;
            DB::afterCommit(fn () => app(TaskCommentPush::class)->deliver($id));
        }
    }

    public function markRead(User $user, Task $task, int $throughId): void
    {
        // Do not acknowledge a newer comment that arrived after the displayed page.
        $this->unread($user, $task)->where('data->last_comment_id', '<=', $throughId)
            ->update(['is_read' => true, 'read_at' => now()]);
    }

    private function unread(User $user, Task $task)
    {
        return Notification::where('user_id', $user->id)->where('type', 'task_comment')
            ->where('is_read', false)->where('data->task_id', $task->id);
    }
}
