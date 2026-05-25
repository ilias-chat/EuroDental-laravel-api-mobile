<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Task;
use App\Models\ServiceProposition;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;

class ServicePropositionController extends Controller
{
    /**
     * Store a new service proposition
     */
    public function store(Request $request, Task $task)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255'
        ]);

        try {
            $user = Auth::user();
            
            // Check if user can propose services (same permissions as managing services)
            $isMainTechnician = $task->technician_id === Auth::id();
            $isHelpingUser = is_array($task->helping_user_ids) && in_array(Auth::id(), $task->helping_user_ids);
            $userPermissions = $user->profile ? $user->profile->permissions->pluck('code') : collect();
            
            if (!$isMainTechnician && !$isHelpingUser && !$userPermissions->contains('tasks_write')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Non autorisé'
                ], 403);
            }

            // Create the proposition
            $proposition = ServiceProposition::create([
                'task_id' => $task->id,
                'proposed_by' => Auth::id(),
                'name' => $validated['name'],
                'status' => 'pending'
            ]);

            // Get admin users with both services_write AND tasks_write permissions
            $adminUsers = User::whereHas('profile.permissions', function($query) {
                $query->where('code', 'services_write');
            })->whereHas('profile.permissions', function($query) {
                $query->where('code', 'tasks_write');
            })->get();

            // Send notifications to admins
            $notificationService = app(NotificationService::class);
            $userName = $user->first_name . ' ' . $user->last_name;
            
            foreach ($adminUsers as $admin) {
                $notificationService->sendToUser(
                    $admin->id,
                    'Nouvelle proposition de service',
                    $userName . ' a proposé le service "' . $validated['name'] . '" pour la tâche ' . $task->task_name,
                    [
                        'type' => 'service_proposition',
                        'proposition_id' => $proposition->id,
                        'task_id' => $task->id,
                        'task_name' => $task->task_name,
                        'service_name' => $validated['name'],
                        'proposed_by_name' => $userName
                    ]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Proposition de service envoyée avec succès',
                'proposition' => [
                    'id' => $proposition->id,
                    'name' => $proposition->name,
                    'status' => $proposition->status,
                    'proposed_by_name' => trim($userName),
                    'created_at' => $proposition->created_at->format('d/m/Y H:i'),
                ],
            ]);
        } catch (\Exception $e) {
            \Log::error('Error creating service proposition: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi de la proposition'
            ], 500);
        }
    }
}
