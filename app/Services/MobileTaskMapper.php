<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class MobileTaskMapper
{
    public static function mapTask(Task $task): array
    {
        $userId = Auth::id();
        $isMainTechnician = $task->technician_id == $userId;
        $isHelpingUser = is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids);

        $helpingUsers = [];
        if (is_array($task->helping_user_ids) && count($task->helping_user_ids) > 0) {
            $helpingUsers = User::with('image')
                ->whereIn('id', $task->helping_user_ids)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'image' => storage_public_url($user->image?->image_name),
                    ];
                })
                ->toArray();
        }

        return [
            'id' => $task->id,
            'client_id' => $task->client_id,
            'task_name' => $task->task_name,
            'task_type' => $task->task_type,
            'description' => $task->description,
            'status' => $task->status,
            'current_visit_status' => $task->getCurrentVisitStatus(),
            'has_ongoing_visit' => (bool) ($task->has_ongoing_visit ?? false),
            'current_user_has_active_visit' => $task->hasUserActiveVisit($userId),
            'urgent' => $task->urgent,
            'task_date' => $task->task_date,
            'started_at' => $task->started_at,
            'finished_at' => $task->finished_at,
            'is_main_technician' => $isMainTechnician,
            'is_helping_user' => $isHelpingUser,
            'is_paid' => (bool) ($task->is_paid ?? false),
            'amount_paid' => $task->amount_paid !== null ? (float) $task->amount_paid : null,
            'admin_delivery_amount' => $task->admin_delivery_amount !== null ? (float) $task->admin_delivery_amount : null,
            'admin_delivery_task_id' => $task->admin_delivery_task_id,
            'hourly_rate' => $task->hourly_rate !== null ? (float) $task->hourly_rate : null,
            'technician' => $task->technician ? [
                'id' => $task->technician->id,
                'name' => $task->technician->first_name . ' ' . $task->technician->last_name,
                'image' => storage_public_url($task->technician?->image?->image_name),
            ] : null,
            'helping_users' => $helpingUsers,
            'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
            'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
            'client_image' => storage_public_url($task->client?->image?->image_name),
            'task_products' => $task->taskProducts->map(function ($taskProduct) {
                return [
                    'id' => $taskProduct->id,
                    'product_name' => $taskProduct->product ? $taskProduct->product->product_name : 'Produit inconnu',
                    'quantity' => $taskProduct->quantity,
                ];
            }),
            'services' => $task->services,
        ];
    }
}
