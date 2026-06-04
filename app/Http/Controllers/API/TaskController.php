<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\ServiceProposition;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    /**
     * Return a list of tasks filtered by technician_id and date (YYYY-MM-DD).
     * Example: /api/tasks?technician_id=1&date=2024-07-10
     */
    public function index(Request $request)
    {
        $query = Task::query();

        if ($request->has('technician_id')) {
            $query->where('technician_id', $request->input('technician_id'));
        }

        if ($request->has('date')) {
            $query->whereDate('task_date', $request->input('date'));
        }

        // Optionally, allow date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->whereBetween('task_date', [$request->input('start_date'), $request->input('end_date')]);
        }

        $tasks = $query->get();

        return response()->json([
            'success' => true,
            'tasks' => $tasks
        ]);
    }

    /**
     * Return a specific task by ID including client details.
     * Example: /api/tasks/43
     */
    public function show($id)
    {
        $task = Task::with([
            'client.image',
            'client.city',
            'technician.image',
            'adminDeliveryReceivedByUser',
            'services',
            'taskProducts.product',
            'events.user.image',
        ])->find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found'
            ], 404);
        }

        $helpingUsers = [];
        if (is_array($task->helping_user_ids) && count($task->helping_user_ids) > 0) {
            $helpingUsers = User::with('image')
                ->whereIn('id', $task->helping_user_ids)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                        'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null,
                    ];
                })
                ->values()
                ->toArray();
        }

        $servicePropositions = ServiceProposition::with('proposer')
            ->where('task_id', $task->id)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'status' => $p->status,
                    'proposed_by' => $p->proposed_by,
                    'proposed_by_name' => $p->proposer
                        ? trim(($p->proposer->first_name ?? '') . ' ' . ($p->proposer->last_name ?? ''))
                        : null,
                    'created_at' => optional($p->created_at)->format('Y-m-d H:i'),
                ];
            })
            ->values();

        $currentUserId = auth('sanctum')->id() ?? Auth::id();
        $isMainTechnician = $currentUserId !== null && (int) $task->technician_id === (int) $currentUserId;
        $isHelpingUser = $currentUserId !== null
            && is_array($task->helping_user_ids)
            && in_array((int) $currentUserId, array_map('intval', $task->helping_user_ids), true);
        $canManageTask = $isMainTechnician || $isHelpingUser;
        $userLastEventType = null;
        if ($currentUserId !== null) {
            $userLastEventType = TaskEvent::where('task_id', $task->id)
                ->where('user_id', $currentUserId)
                ->orderByDesc('id')
                ->value('event_type');
        }

        $events = $task->events->map(function ($event) {
            return [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'event_time' => optional($event->event_time)->toISOString(),
                'event_time_label' => optional($event->event_time)->format('d/m/Y H:i'),
                'latitude' => $event->latitude,
                'longitude' => $event->longitude,
                'user_id' => $event->user_id,
                'user_name' => $event->user
                    ? trim(($event->user->first_name ?? '') . ' ' . ($event->user->last_name ?? ''))
                    : null,
                'user_image' => $event->user && $event->user->image && $event->user->image->image_name
                    ? asset('storage/' . $event->user->image->image_name)
                    : null,
            ];
        })->values();

        $responseTask = [
            'id' => $task->id,
            'client_id' => $task->client_id,
            'task_name' => $task->task_name,
            'task_type' => $task->task_type,
            'description' => $task->description,
            'status' => $task->status,
            'current_visit_status' => $task->getCurrentVisitStatus(),
            'has_ongoing_visit' => (bool) ($task->has_ongoing_visit ?? false),
            'urgent' => (bool) $task->urgent,
            'task_date' => optional($task->task_date)->format('Y-m-d'),
            'started_at' => optional($task->started_at)->toISOString(),
            'finished_at' => optional($task->finished_at)->toISOString(),
            'is_paid' => (bool) ($task->is_paid ?? false),
            'amount_paid' => $task->amount_paid !== null ? (float) $task->amount_paid : null,
            'admin_delivery_amount' => $task->admin_delivery_amount !== null ? (float) $task->admin_delivery_amount : null,
            'admin_delivery_task_id' => $task->admin_delivery_task_id,
            'admin_delivery_received_by_user_id' => $task->admin_delivery_received_by_user_id,
            'admin_delivery_received_by_user_name' => $task->adminDeliveryReceivedByUser
                ? trim(($task->adminDeliveryReceivedByUser->first_name ?? '') . ' ' . ($task->adminDeliveryReceivedByUser->last_name ?? ''))
                : null,
            'hourly_rate' => $task->hourly_rate !== null ? (float) $task->hourly_rate : null,
            'technician_id' => $task->technician_id,
            'technician_name' => $task->technician
                ? trim(($task->technician->first_name ?? '') . ' ' . ($task->technician->last_name ?? ''))
                : null,
            'technician_image' => $task->technician && $task->technician->image
                ? asset('storage/' . $task->technician->image->image_name)
                : null,
            'technician' => $task->technician
                ? [
                    'id' => $task->technician->id,
                    'name' => trim(($task->technician->first_name ?? '') . ' ' . ($task->technician->last_name ?? '')),
                    'image' => $task->technician->image ? asset('storage/' . $task->technician->image->image_name) : null,
                ]
                : null,
            'current_user_id' => $currentUserId,
            'is_main_technician' => $isMainTechnician,
            'can_manage_task' => $canManageTask,
            'user_last_event' => $userLastEventType,
            'helping_users' => $helpingUsers,
            'client_name' => $task->client
                ? trim(($task->client->first_name ?? '') . ' ' . ($task->client->last_name ?? ''))
                : null,
            'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
            'client_image' => $task->client && $task->client->image ? asset('storage/' . $task->client->image->image_name) : null,
            'services' => $task->services->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'description' => $service->description,
                    'price' => $service->pivot && $service->pivot->price !== null
                        ? (float) $service->pivot->price
                        : ($service->price !== null ? (float) $service->price : null),
                ];
            })->values(),
            'service_propositions' => $servicePropositions,
            'task_products' => $task->taskProducts->map(function ($taskProduct) {
                return [
                    'id' => $taskProduct->id,
                    'product_name' => $taskProduct->product ? $taskProduct->product->product_name : 'Produit inconnu',
                    'quantity' => $taskProduct->quantity,
                ];
            })->values(),
            'events' => $events,
        ];

        return response()->json([
            'success' => true,
            'task' => $responseTask,
        ]);
    }

    /**
     * Update task status to "en cours"
     * Example: PUT /api/tasks/{id}/start
     */
    public function start($id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found'
            ], 404);
        }

        // Update the task status to "en cours"
        $task->update([
            'status' => 'en cours',
            'started_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Statut de la tâche mis à jour vers "en cours" avec succès',
            'task' => $task
        ]);
    }

    /**
     * Finish a task and set finished_at timestamp
     * Example: PUT /api/tasks/{id}/finish
     */
    public function finish($id)
    {
        $task = Task::find($id);

        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Tâche introuvable'
            ], 404);
        }

        // Update the task status to "terminé" and set finished_at
        $task->update([
            'status' => 'terminée',
            'finished_at' => now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tâche terminée avec succès',
            'task' => $task
        ]);
    }

    /**
     * Update task description from tasks app details drawer.
     */
    public function updateDescription(Request $request, $id)
    {
        $validated = $request->validate([
            'description' => 'nullable|string|max:1000',
        ]);

        $task = Task::find($id);
        if (!$task) {
            return response()->json([
                'success' => false,
                'message' => 'Task not found'
            ], 404);
        }

        $task->update([
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Description mise à jour avec succès',
            'description' => $task->description,
        ]);
    }
} 