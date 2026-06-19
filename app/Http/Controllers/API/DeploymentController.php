<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\DeploymentExpense;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DeploymentController extends Controller
{
    private function userIsInDeployment(Deployment $deployment): bool
    {
        $userId = Auth::id();
        if ($userId === null) {
            return false;
        }
        $userId = (int) $userId;
        $teamIds = array_values(array_map('intval', (array) ($deployment->team_member_ids ?? [])));
        $hosterIds = array_values(array_map('intval', (array) ($deployment->hosters ?? [])));

        if ($deployment->responsible_id !== null && (int) $deployment->responsible_id === $userId) {
            return true;
        }
        if ($deployment->driver_id !== null && (int) $deployment->driver_id === $userId) {
            return true;
        }
        if (in_array($userId, $teamIds, true)) {
            return true;
        }
        if (in_array($userId, $hosterIds, true)) {
            return true;
        }

        return false;
    }

    private function userIsInTask($task, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }
        if ($task->technician_id !== null && (int) $task->technician_id === $userId) {
            return true;
        }
        $helpingIds = array_values(array_map('intval', (array) ($task->helping_user_ids ?? [])));

        return in_array($userId, $helpingIds, true);
    }

    private function tasksForCurrentUser($tasks, ?int $userId)
    {
        if ($userId === null) {
            return $tasks->filter(function () {
                return false;
            });
        }

        return $tasks->filter(function ($task) use ($userId) {
            return $this->userIsInTask($task, $userId);
        });
    }

    private function tasksToShowInDeployment(Deployment $deployment, ?int $userId)
    {
        if ($userId === null) {
            return $deployment->tasks->filter(function () {
                return false;
            });
        }
        $isResponsible = $deployment->responsible_id !== null && (int) $deployment->responsible_id === $userId;

        return $isResponsible ? $deployment->tasks : $this->tasksForCurrentUser($deployment->tasks, $userId);
    }

    private function mapDeploymentSummary(Deployment $deployment): array
    {
        $userId = Auth::id();
        $tasksToShow = $this->tasksToShowInDeployment($deployment, $userId);
        $teamMembers = \App\Models\User::whereIn('id', $deployment->team_member_ids ?? [])
            ->with('image')
            ->get();

        return [
            'id' => $deployment->id,
            'title' => $deployment->title,
            'deployment_date' => $deployment->deployment_date ? $deployment->deployment_date->format('Y-m-d') : null,
            'description' => $deployment->description,
            'city_id' => $deployment->city_id,
            'city_name' => $deployment->city ? $deployment->city->name : null,
            'responsible_id' => $deployment->responsible_id,
            'responsible_name' => $deployment->responsible ? ($deployment->responsible->first_name.' '.$deployment->responsible->last_name) : null,
            'responsible_image' => $deployment->responsible && $deployment->responsible->image ? asset('storage/'.$deployment->responsible->image->image_name) : null,
            'driver_id' => $deployment->driver_id,
            'driver_name' => $deployment->driver ? ($deployment->driver->first_name.' '.$deployment->driver->last_name) : null,
            'driver_image' => $deployment->driver && $deployment->driver->image ? asset('storage/'.$deployment->driver->image->image_name) : null,
            'team_member_ids' => $deployment->team_member_ids ?? [],
            'team_members' => $teamMembers->map(function ($member) {
                return [
                    'id' => $member->id,
                    'name' => $member->first_name.' '.$member->last_name,
                    'image' => $member->image && $member->image->image_name ? asset('storage/'.$member->image->image_name) : null,
                ];
            })->toArray(),
            'tasks_count' => $tasksToShow->count(),
            'tasks' => $tasksToShow->map(function ($task) {
                return [
                    'id' => $task->id,
                    'task_name' => $task->task_name,
                    'status' => $task->status,
                    'task_type' => $task->task_type,
                ];
            })->values()->toArray(),
            'all_tasks_completed' => $tasksToShow->count() > 0 && $tasksToShow->every(function ($task) {
                return in_array($task->status, ['terminée', 'annulée']);
            }),
            'completed_tasks_count' => $tasksToShow->filter(function ($task) {
                return in_array($task->status, ['terminée', 'annulée']);
            })->count(),
            'completion_percentage' => $tasksToShow->count() > 0
                ? round(($tasksToShow->filter(function ($task) {
                    return in_array($task->status, ['terminée', 'annulée']);
                })->count() / $tasksToShow->count()) * 100)
                : 0,
        ];
    }

    public function dayDeployments(Request $request): JsonResponse
    {
        try {
            $dateStr = $request->query('date', Carbon::now()->toDateString());
            $date = Carbon::parse($dateStr)->format('Y-m-d');

            $deployments = Deployment::with([
                'responsible.image',
                'driver.image',
                'city',
                'tasks.technician.image',
                'tasks.client.image',
                'tasks.client.city',
            ])
                ->whereDate('deployment_date', $date)
                ->get()
                ->filter(fn ($deployment) => $this->userIsInDeployment($deployment))
                ->map(fn ($deployment) => $this->mapDeploymentSummary($deployment));

            return response()->json([
                'success' => true,
                'deployments' => $deployments->values()->toArray(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in dayDeployments: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement des déplacements',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function monthDeployments(Request $request): JsonResponse
    {
        try {
            $monthStr = $request->query('month', Carbon::now()->format('Y-m'));
            $month = Carbon::parse($monthStr.'-01');
            $startOfMonth = $month->copy()->startOfMonth();
            $endOfMonth = $month->copy()->endOfMonth();

            $deployments = Deployment::with(['tasks'])
                ->whereBetween('deployment_date', [$startOfMonth, $endOfMonth])
                ->get()
                ->filter(fn ($deployment) => $this->userIsInDeployment($deployment))
                ->map(function ($deployment) {
                    $userId = Auth::id();
                    $tasksToShow = $this->tasksToShowInDeployment($deployment, $userId);
                    $allTasksCompleted = $tasksToShow->count() > 0 && $tasksToShow->every(function ($task) {
                        return in_array($task->status, ['terminée', 'annulée']);
                    });

                    return [
                        'id' => $deployment->id,
                        'deployment_date' => $deployment->deployment_date ? $deployment->deployment_date->format('Y-m-d') : null,
                        'tasks_count' => $tasksToShow->count(),
                        'all_tasks_completed' => $allTasksCompleted,
                    ];
                })
                ->groupBy(fn ($deployment) => $deployment['deployment_date'])
                ->map(fn ($deploymentsForDate) => $deploymentsForDate->values()->toArray())
                ->toArray();

            return response()->json([
                'success' => true,
                'deployments' => $deployments,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in monthDeployments: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors du chargement des déplacements',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function show(Request $request, $id): JsonResponse
    {
        $deployment = Deployment::with([
            'responsible.image',
            'driver.image',
            'city',
            'tasks.technician.image',
            'tasks.client.image',
            'tasks.client.city',
            'tasks.services',
            'events.user.image',
        ])->findOrFail($id);

        $isInDeployment = $this->userIsInDeployment($deployment);
        $userId = Auth::id();
        $canViewAll = Auth::user()->profile && Auth::user()->profile->permissions->pluck('code')->contains('tasks_view_all');
        if (! $isInDeployment && ! $canViewAll) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $teamMembers = \App\Models\User::whereIn('id', $deployment->team_member_ids ?? [])
            ->with('image')
            ->get();

        $expenses = [];
        try {
            $deployment->load('expenses');
            $expenses = $deployment->expenses->map(function ($expense) {
                return [
                    'id' => $expense->id,
                    'description' => $expense->description,
                    'amount' => (float) $expense->amount,
                    'expense_date' => $expense->expense_date->format('Y-m-d'),
                    'category' => $expense->category,
                ];
            })->toArray();
        } catch (\Exception $e) {
            \Log::warning('Expenses table may not exist: '.$e->getMessage());
        }

        $tasksToShow = $this->tasksToShowInDeployment($deployment, $userId);
        $tasks = $tasksToShow->map(function ($task) use ($userId) {
            $helpingUsers = [];
            if ($task->helping_user_ids && is_array($task->helping_user_ids)) {
                $users = \App\Models\User::whereIn('id', $task->helping_user_ids)->with('image')->get();
                $helpingUsers = $users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->first_name.' '.$user->last_name,
                        'image' => $user->image && $user->image->image_name ? asset('storage/'.$user->image->image_name) : null,
                    ];
                })->toArray();
            }
            $userCanAct = $this->userIsInTask($task, $userId);

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
                'task_date' => $task->task_date ? $task->task_date->format('Y-m-d') : null,
                'started_at' => $task->started_at,
                'finished_at' => $task->finished_at,
                'events' => [],
                'is_main_technician' => $task->technician_id == $userId,
                'is_helping_user' => is_array($task->helping_user_ids) && in_array($userId, array_map('intval', (array) $task->helping_user_ids)),
                'user_can_act' => $userCanAct,
                'is_paid' => (bool) ($task->is_paid ?? false),
                'amount_paid' => $task->amount_paid !== null ? (float) $task->amount_paid : null,
                'admin_delivery_amount' => $task->admin_delivery_amount !== null ? (float) $task->admin_delivery_amount : null,
                'admin_delivery_task_id' => $task->admin_delivery_task_id,
                'hourly_rate' => $task->hourly_rate !== null ? (float) $task->hourly_rate : null,
                'technician' => $task->technician ? [
                    'id' => $task->technician->id,
                    'name' => $task->technician->first_name.' '.$task->technician->last_name,
                    'image' => $task->technician->image && $task->technician->image->image_name ? asset('storage/'.$task->technician->image->image_name) : null,
                ] : null,
                'helping_users' => $helpingUsers,
                'client_name' => $task->client ? ($task->client->first_name.' '.$task->client->last_name) : null,
                'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                'client_image' => $task->client && $task->client->image ? $task->client->image->image_name : null,
                'services' => $task->services->map(function ($service) {
                    return [
                        'id' => $service->id,
                        'name' => $service->name,
                        'description' => $service->description,
                    ];
                })->toArray(),
                'service_propositions' => [],
            ];
        });

        return response()->json([
            'success' => true,
            'deployment' => [
                'id' => $deployment->id,
                'title' => $deployment->title,
                'deployment_date' => $deployment->deployment_date ? $deployment->deployment_date->format('Y-m-d') : null,
                'description' => $deployment->description,
                'city_id' => $deployment->city_id,
                'city_name' => $deployment->city ? $deployment->city->name : null,
                'responsible_id' => $deployment->responsible_id,
                'responsible_name' => $deployment->responsible ? ($deployment->responsible->first_name.' '.$deployment->responsible->last_name) : null,
                'responsible_image' => $deployment->responsible && $deployment->responsible->image ? asset('storage/'.$deployment->responsible->image->image_name) : null,
                'driver_id' => $deployment->driver_id,
                'driver_name' => $deployment->driver ? ($deployment->driver->first_name.' '.$deployment->driver->last_name) : null,
                'driver_image' => $deployment->driver && $deployment->driver->image ? asset('storage/'.$deployment->driver->image->image_name) : null,
                'team_member_ids' => $deployment->team_member_ids ?? [],
                'team_members' => $teamMembers->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->first_name.' '.$member->last_name,
                        'image' => $member->image && $member->image->image_name ? asset('storage/'.$member->image->image_name) : null,
                    ];
                })->toArray(),
                'tasks_count' => $tasksToShow->count(),
                'tasks' => $tasks->values()->toArray(),
                'completed_tasks_count' => $tasksToShow->filter(function ($task) {
                    return in_array($task->status, ['terminée', 'annulée']);
                })->count(),
                'completion_percentage' => $tasksToShow->count() > 0
                    ? round(($tasksToShow->filter(function ($task) {
                        return in_array($task->status, ['terminée', 'annulée']);
                    })->count() / $tasksToShow->count()) * 100)
                    : 0,
                'expenses' => $expenses,
                'is_responsible' => $deployment->responsible_id == $userId,
                'hosters' => $deployment->hosters ?? [],
                'hosters_detail' => $deployment->getHosters()->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => $member->first_name.' '.$member->last_name,
                        'image' => $member->image && $member->image->image_name ? asset('storage/'.$member->image->image_name) : null,
                    ];
                })->values()->toArray(),
                'events' => $deployment->events->map(function ($event) {
                    return [
                        'id' => $event->id,
                        'event_type' => $event->event_type,
                        'user_id' => $event->user_id,
                        'user_name' => $event->user ? $event->user->first_name.' '.$event->user->last_name : null,
                        'user_image' => $event->user && $event->user->image && $event->user->image->image_name ? asset('storage/'.$event->user->image->image_name) : null,
                        'event_time' => $event->event_time ? $event->event_time->toIso8601String() : $event->created_at->toIso8601String(),
                        'created_at' => $event->created_at->toIso8601String(),
                    ];
                })->values()->toArray(),
            ],
        ]);
    }

    public function storeExpense(Request $request, $id): JsonResponse
    {
        $deployment = Deployment::findOrFail($id);

        if ($deployment->responsible_id != Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le responsable du déplacement peut ajouter des dépenses',
            ], 403);
        }

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'category' => 'nullable|string|max:255',
        ]);

        $expense = $deployment->expenses()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Dépense ajoutée avec succès',
            'expense' => [
                'id' => $expense->id,
                'description' => $expense->description,
                'amount' => (float) $expense->amount,
                'expense_date' => $expense->expense_date->format('Y-m-d'),
                'category' => $expense->category,
            ],
        ]);
    }

    public function updateExpense(Request $request, $id, $expenseId): JsonResponse
    {
        $deployment = Deployment::findOrFail($id);
        $expense = DeploymentExpense::where('deployment_id', $id)->findOrFail($expenseId);

        if ($deployment->responsible_id != Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le responsable du déplacement peut modifier des dépenses',
            ], 403);
        }

        $validated = $request->validate([
            'description' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'expense_date' => 'required|date',
            'category' => 'nullable|string|max:255',
        ]);

        $expense->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Dépense mise à jour avec succès',
            'expense' => [
                'id' => $expense->id,
                'description' => $expense->description,
                'amount' => (float) $expense->amount,
                'expense_date' => $expense->expense_date->format('Y-m-d'),
                'category' => $expense->category,
            ],
        ]);
    }

    public function deleteExpense(Request $request, $id, $expenseId): JsonResponse
    {
        $deployment = Deployment::findOrFail($id);
        $expense = DeploymentExpense::where('deployment_id', $id)->findOrFail($expenseId);

        if ($deployment->responsible_id != Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le responsable du déplacement peut supprimer des dépenses',
            ], 403);
        }

        $expense->delete();

        return response()->json([
            'success' => true,
            'message' => 'Dépense supprimée avec succès',
        ]);
    }
}
