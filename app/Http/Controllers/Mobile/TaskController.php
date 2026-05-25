<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Task;
use App\Models\Client;
use App\Models\User;
use App\Models\ProposedTask;
use App\Models\TaskType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use App\Services\NotificationService;
use App\Services\WarrantyService;
use App\Mail\TaskAssignedMail;
use App\Mail\TaskProposedMail;

class TaskController extends Controller
{
    public function index()
    {
        // Get a broader date range to cover calendar navigation
        $startDate = Carbon::now()->subMonths(1)->startOfMonth();
        $endDate = Carbon::now()->addMonths(1)->endOfMonth();
        
        // Fetch tasks where user is either the main technician OR a helping user
        // Exclude tasks that belong to a deployment
        $tasks = Task::with(['client.image', 'client.city', 'taskProducts.product', 'services', 'events', 'technician.image', 'adminDeliveryReceivedByUser'])
            ->where(function($query) {
                $query->where('technician_id', Auth::id())
                      ->orWhereJsonContains('helping_user_ids', Auth::id());
            })
            ->whereNull('deployment_id')
            ->whereBetween('task_date', [$startDate, $endDate])
            ->orderBy('task_date', 'asc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($task) {
                $userId = Auth::id();
                $isMainTechnician = $task->technician_id == $userId;
                $isHelpingUser = is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids);
                
                // Get helping users data
                $helpingUsers = [];
                if (is_array($task->helping_user_ids) && count($task->helping_user_ids) > 0) {
                    $helpingUsers = User::with('image')
                        ->whereIn('id', $task->helping_user_ids)
                        ->get()
                        ->map(function($user) {
                            return [
                                'id' => $user->id,
                                'name' => $user->first_name . ' ' . $user->last_name,
                                'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null
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
                    'events' => [],
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
                        'image' => $task->technician->image && $task->technician->image->image_name ? asset('storage/' . $task->technician->image->image_name) : null
                    ] : null,
                    'helping_users' => $helpingUsers,
                    'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
                    'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                    'client_image' => $task->client && $task->client->image ? $task->client->image->image_name : null,
                    'task_products' => $task->taskProducts->map(function($taskProduct) {
                        return [
                            'id' => $taskProduct->id,
                            'product_name' => $taskProduct->product ? $taskProduct->product->product_name : 'Produit inconnu',
                            'quantity' => $taskProduct->quantity
                        ];
                    }),
                    'services' => $task->services
                ];
            });

        // Load all clients for the create task modal
        $clients = Client::with(['image', 'city'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function($client) {
                return [
                    'id' => $client->id,
                    'name' => $client->first_name . ' ' . $client->last_name,
                    'city' => $client->city ? $client->city->name : null,
                    'image' => $client->image && $client->image->image_name ? asset('storage/' . $client->image->image_name) : null
                ];
            });

        $technicians = User::with(['image', 'profile'])
            ->where('is_blocked', false)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null,
                    'profile' => $user->profile ? $user->profile->profile_name : null
                ];
            });

        // Server-render the task types so the dropdown is never empty on first paint
        // (prevents race condition where the modal opens before the async fetch resolves).
        $taskTypes = TaskType::orderBy('name')->get(['id', 'name']);

        return view('mobile.tasks', compact('tasks', 'clients', 'technicians', 'taskTypes'));
    }

    public function admin(Request $request)
    {
        $selectedUserId = $request->get('user_id');
        
        // Load only current month initially
        $currentMonth = Carbon::now();
        $startDate = $currentMonth->copy()->startOfMonth();
        $endDate = $currentMonth->copy()->endOfMonth();
        
        // Fetch tasks for current month only
        // Exclude tasks that belong to a deployment
        $allTasks = Task::with(['client.image', 'client.city', 'technician.image', 'technician.profile', 'taskProducts.product', 'services', 'events', 'adminDeliveryReceivedByUser'])
            ->whereNull('deployment_id')
            ->whereBetween('task_date', [$startDate, $endDate])
            ->orderBy('task_date', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Get ALL users who have tasks (across all time) and are not blocked
        $allUsersWithTasks = User::with(['image', 'profile'])
            ->where('is_blocked', false)
            ->whereHas('tasks') // Users who have at least one task ever
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null,
                    'profile' => $user->profile ? $user->profile->profile_name : 'Aucun profil',
                    'task_count' => 0 // Will be calculated dynamically in frontend
                ];
            });
        
        // Map tasks using EXACT SAME structure as regular tasks
        $tasks = $allTasks->map(function($task) {
                // Get helping users data
                $helpingUsers = [];
                if (is_array($task->helping_user_ids) && count($task->helping_user_ids) > 0) {
                    $helpingUsers = User::with('image')
                        ->whereIn('id', $task->helping_user_ids)
                        ->get()
                        ->map(function($user) {
                            return [
                                'id' => $user->id,
                                'name' => $user->first_name . ' ' . $user->last_name,
                                'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null
                            ];
                        })->toArray();
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
                    'current_user_has_active_visit' => $task->hasUserActiveVisit(Auth::id()),
                    'current_user_is_en_route' => $task->isUserEnRoute(Auth::id()),
                    'events' => [],
                    'urgent' => $task->urgent,
                    'task_date' => $task->task_date,
                    'started_at' => $task->started_at,
                    'finished_at' => $task->finished_at,
                    'is_paid' => (bool) ($task->is_paid ?? false),
                    'amount_paid' => $task->amount_paid !== null ? (float) $task->amount_paid : null,
                    'admin_delivery_amount' => $task->admin_delivery_amount !== null ? (float) $task->admin_delivery_amount : null,
                    'admin_delivery_task_id' => $task->admin_delivery_task_id,
                    'admin_delivery_received_by_user_id' => $task->admin_delivery_received_by_user_id,
                    'admin_delivery_received_by_user_name' => $task->adminDeliveryReceivedByUser ? $task->adminDeliveryReceivedByUser->first_name . ' ' . $task->adminDeliveryReceivedByUser->last_name : null,
                    'hourly_rate' => $task->hourly_rate !== null ? (float) $task->hourly_rate : null,
                    'technician_id' => $task->technician_id, // Add for filtering
                    'technician_name' => $task->technician ? $task->technician->first_name . ' ' . $task->technician->last_name : null,
                    'technician_image' => $task->technician && $task->technician->image ? asset('storage/' . $task->technician->image->image_name) : null,
                    'is_main_technician' => $task->technician_id == Auth::id(),
                    'helping_users' => $helpingUsers,
                    'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
                    'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                    'client_image' => $task->client && $task->client->image ? asset('storage/' . $task->client->image->image_name) : null,
                    'task_products' => $task->taskProducts->map(function($taskProduct) {
                        return [
                            'id' => $taskProduct->id,
                            'product_name' => $taskProduct->product ? $taskProduct->product->product_name : 'Produit inconnu',
                            'quantity' => $taskProduct->quantity
                        ];
                    }),
                    'services' => $task->services
                ];
            });

        $totalTaskCount = $allTasks->count();

        $clients = Client::with(['image', 'city'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function($client) {
                return [
                    'id' => $client->id,
                    'name' => $client->first_name . ' ' . $client->last_name,
                    'city' => $client->city ? $client->city->name : null,
                    'image' => $client->image && $client->image->image_name ? asset('storage/' . $client->image->image_name) : null
                ];
            });

        $technicians = User::with(['image', 'profile'])
            ->where('is_blocked', false)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null,
                    'profile' => $user->profile ? $user->profile->profile_name : null
                ];
            });

        // Server-render the task types so the dropdown is never empty on first paint.
        $taskTypes = TaskType::orderBy('name')->get(['id', 'name']);

        return view('mobile.tasks-admin', compact('tasks', 'allUsersWithTasks', 'selectedUserId', 'totalTaskCount', 'clients', 'technicians', 'taskTypes'));
    }

    public function adminMonth(Request $request)
    {
        $year = $request->get('year', Carbon::now()->year);
        $month = $request->get('month', Carbon::now()->month);
        
        // Create date range for the specified month
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();
        
        // Fetch tasks for the specified month
        // Exclude tasks that belong to a deployment
        $allTasks = Task::with(['client.image', 'client.city', 'technician.image', 'technician.profile', 'taskProducts.product', 'services', 'events', 'adminDeliveryReceivedByUser'])
            ->whereNull('deployment_id')
            ->whereBetween('task_date', [$startDate, $endDate])
            ->orderBy('task_date', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Don't return users list - frontend will keep the same users and update counts dynamically
        
        // Map tasks using same structure
        $tasks = $allTasks->map(function($task) {
            // Get helping users data
            $helpingUsers = [];
            if (is_array($task->helping_user_ids) && count($task->helping_user_ids) > 0) {
                $helpingUsers = User::with('image')
                    ->whereIn('id', $task->helping_user_ids)
                    ->get()
                    ->map(function($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->first_name . ' ' . $user->last_name,
                            'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null
                        ];
                    })->toArray();
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
                'current_user_has_active_visit' => $task->hasUserActiveVisit(Auth::id()),
                'current_user_is_en_route' => $task->isUserEnRoute(Auth::id()),
                'urgent' => $task->urgent,
                'task_date' => $task->task_date,
                'started_at' => $task->started_at,
                'finished_at' => $task->finished_at,
                'is_paid' => (bool) ($task->is_paid ?? false),
                'amount_paid' => $task->amount_paid !== null ? (float) $task->amount_paid : null,
                'admin_delivery_amount' => $task->admin_delivery_amount !== null ? (float) $task->admin_delivery_amount : null,
                'admin_delivery_task_id' => $task->admin_delivery_task_id,
                'admin_delivery_received_by_user_id' => $task->admin_delivery_received_by_user_id,
                'admin_delivery_received_by_user_name' => $task->adminDeliveryReceivedByUser ? $task->adminDeliveryReceivedByUser->first_name . ' ' . $task->adminDeliveryReceivedByUser->last_name : null,
                'hourly_rate' => $task->hourly_rate !== null ? (float) $task->hourly_rate : null,
                'technician_id' => $task->technician_id,
                'technician_name' => $task->technician ? $task->technician->first_name . ' ' . $task->technician->last_name : null,
                'technician_image' => $task->technician && $task->technician->image ? asset('storage/' . $task->technician->image->image_name) : null,
                'is_main_technician' => $task->technician_id == Auth::id(),
                'helping_users' => $helpingUsers,
                'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
                'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                'client_image' => $task->client && $task->client->image ? asset('storage/' . $task->client->image->image_name) : null,
                'task_products' => $task->taskProducts->map(function($taskProduct) {
                    return [
                        'id' => $taskProduct->id,
                        'product_name' => $taskProduct->product ? $taskProduct->product->product_name : 'Produit inconnu',
                        'quantity' => $taskProduct->quantity
                    ];
                })
            ];
        });

        $totalTaskCount = $allTasks->count();

        return response()->json([
            'tasks' => $tasks,
            'totalTaskCount' => $totalTaskCount,
            'year' => $year,
            'month' => $month
        ]);
    }

    public function show(Task $task)
    {
        return view('mobile.task-detail', compact('task'));
    }

    public function store(Request $request)
    {
        \Log::info('Create task request data:', $request->all());
        
        $request->validate([
            'task_name' => 'required|string|max:255',
            'task_type' => 'required|string',
            'description' => 'nullable|string',
            'client_id' => 'nullable|exists:clients,id',
            'technician_id' => 'nullable|exists:users,id',
            'task_date' => 'required|date',
            'deployment_id' => 'nullable|exists:deployments,id',
            'helping_user_ids' => 'nullable|array',
            'helping_user_ids.*' => 'exists:users,id'
        ]);

        try {
            $task = Task::create([
                'task_name' => $request->task_name,
                'task_type' => $request->task_type,
                'description' => $request->description ?: null,
                'client_id' => $request->client_id,
                'task_date' => $request->task_date,
                'technician_id' => $request->technician_id ?? Auth::id(),
                'create_by' => Auth::id(),
                'status' => 'en attente',
                'urgent' => false,
                'deployment_id' => $request->deployment_id,
                'helping_user_ids' => $request->helping_user_ids ?? []
            ]);
        } catch (\Exception $e) {
            \Log::error('Task creation failed:', [
                'error' => $e->getMessage(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création de la tâche: ' . $e->getMessage()
            ], 500);
        }

        // Send push notification and email to technician if assigned
        $technicianId = $request->technician_id;
        if ($technicianId && $technicianId != Auth::id()) {
            try {
                $notificationService = app(NotificationService::class);
                $urgentText = $request->urgent ? ' (URGENT)' : '';
                $clientName = '';
                $clientNameFull = '';
                
                // Get client name if available
                if ($request->client_id) {
                    $client = \App\Models\Client::find($request->client_id);
                    if ($client) {
                        $clientName = " pour {$client->first_name} {$client->last_name}";
                        $clientNameFull = "{$client->first_name} {$client->last_name}";
                    }
                }
                
                // Get technician info
                $technician = \App\Models\User::find($technicianId);
                $technicianName = $technician ? "{$technician->first_name} {$technician->last_name}" : '';
                
                // Format task date for display
                $taskDate = \Carbon\Carbon::parse($request->task_date)->format('d/m/Y');
                
                // Send push notification
                try {
                    $notificationService->sendToUser(
                        $technicianId,
                        'Nouvelle tâche assignée' . $urgentText,
                        "Tâche: {$request->task_name}{$clientName} - Date: {$taskDate}",
                        [
                            'type' => 'task_assigned',
                            'task_id' => $task->id,
                            'task_name' => $request->task_name,
                            'urgent' => $request->urgent ?? false,
                            'task_date' => $request->task_date,
                            'action' => 'view_task'
                        ]
                    );
                    \Log::info('Push notification sent successfully', ['task_id' => $task->id]);
                } catch (\Exception $e) {
                    \Log::error('Failed to send push notification: ' . $e->getMessage(), [
                        'task_id' => $task->id,
                        'technician_id' => $technicianId
                    ]);
                }
                
                // Send email notification
                if ($technician && $technician->email) {
                    try {
                        \Log::info('Attempting to send email to: ' . $technician->email, [
                            'task_id' => $task->id,
                            'technician_name' => $technicianName,
                            'client_name' => $clientNameFull,
                            'task_name' => $request->task_name,
                            'task_date' => $taskDate
                        ]);
                        
                        // Refresh task with relationships
                        $taskForEmail = Task::with(['client', 'technician'])->find($task->id);
                        
                        Mail::to($technician->email)->send(
                            new TaskAssignedMail($taskForEmail, $technicianName, $clientNameFull, $taskDate)
                        );
                        
                        \Log::info('Email sent successfully', ['task_id' => $task->id, 'email' => $technician->email]);
                    } catch (\Exception $e) {
                        \Log::error('Failed to send email notification: ' . $e->getMessage(), [
                            'task_id' => $task->id,
                            'technician_id' => $technicianId,
                            'email' => $technician->email,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Log general notification error but don't fail the task creation
                \Log::error('Failed to send task notifications: ' . $e->getMessage(), [
                    'task_id' => $task->id,
                    'technician_id' => $technicianId
                ]);
            }
        }

        // Send notifications to helping users
        if ($request->helping_user_ids && is_array($request->helping_user_ids) && count($request->helping_user_ids) > 0) {
            try {
                $notificationService = app(NotificationService::class);
                $urgentText = $request->urgent ? ' (URGENT)' : '';
                $clientName = '';
                $clientNameFull = '';
                
                // Get client name if available
                if ($request->client_id) {
                    $client = \App\Models\Client::find($request->client_id);
                    if ($client) {
                        $clientName = " pour {$client->first_name} {$client->last_name}";
                        $clientNameFull = "{$client->first_name} {$client->last_name}";
                    }
                }
                
                // Format task date for display
                $taskDate = \Carbon\Carbon::parse($request->task_date)->format('d/m/Y');
                
                // Send notifications to each helping user
                foreach ($request->helping_user_ids as $helpingUserId) {
                    if ($helpingUserId != Auth::id()) { // Don't notify the creator
                        try {
                            // Get helping user info
                            $helpingUser = \App\Models\User::find($helpingUserId);
                            $helpingUserName = $helpingUser ? "{$helpingUser->first_name} {$helpingUser->last_name}" : '';
                            
                            // Send push notification
                            try {
                                $notificationService->sendToUser(
                                    $helpingUserId,
                                    'Nouvelle tâche assignée' . $urgentText,
                                    "Tâche: {$request->task_name}{$clientName} - Date: {$taskDate}",
                                    [
                                        'type' => 'task_assigned',
                                        'task_id' => $task->id,
                                        'task_name' => $request->task_name,
                                        'urgent' => $request->urgent ?? false,
                                        'task_date' => $request->task_date,
                                        'action' => 'view_task'
                                    ]
                                );
                                \Log::info('Push notification sent to helping user', [
                                    'task_id' => $task->id,
                                    'helping_user_id' => $helpingUserId
                                ]);
                            } catch (\Exception $e) {
                                \Log::error('Failed to send push notification to helping user: ' . $e->getMessage(), [
                                    'task_id' => $task->id,
                                    'helping_user_id' => $helpingUserId
                                ]);
                            }
                            
                            // Send email notification
                            if ($helpingUser && $helpingUser->email) {
                                try {
                                    \Log::info('Attempting to send email to helping user: ' . $helpingUser->email, [
                                        'task_id' => $task->id,
                                        'helping_user_name' => $helpingUserName,
                                        'client_name' => $clientNameFull,
                                        'task_name' => $request->task_name,
                                        'task_date' => $taskDate
                                    ]);
                                    
                                    // Refresh task with relationships
                                    $taskForEmail = Task::with(['client', 'technician'])->find($task->id);
                                    
                                    Mail::to($helpingUser->email)->send(
                                        new TaskAssignedMail($taskForEmail, $helpingUserName, $clientNameFull, $taskDate)
                                    );
                                    
                                    \Log::info('Email sent successfully to helping user', [
                                        'task_id' => $task->id,
                                        'email' => $helpingUser->email
                                    ]);
                                } catch (\Exception $e) {
                                    \Log::error('Failed to send email notification to helping user: ' . $e->getMessage(), [
                                        'task_id' => $task->id,
                                        'helping_user_id' => $helpingUserId,
                                        'email' => $helpingUser->email,
                                        'error' => $e->getMessage(),
                                        'trace' => $e->getTraceAsString()
                                    ]);
                                }
                            }
                        } catch (\Exception $e) {
                            \Log::error('Failed to send notification to helping user: ' . $e->getMessage(), [
                                'task_id' => $task->id,
                                'helping_user_id' => $helpingUserId
                            ]);
                        }
                    }
                }
            } catch (\Exception $e) {
                // Log general helping users notification error but don't fail the task creation
                \Log::error('Failed to send helping users notifications: ' . $e->getMessage(), [
                    'task_id' => $task->id,
                    'helping_user_ids' => $request->helping_user_ids
                ]);
            }
        }

        // Load relationships for consistent response
        $task->load(['client.image', 'client.city', 'technician.image']);

        // Get helping users data
        $helpingUsers = [];
        if (is_array($task->helping_user_ids) && count($task->helping_user_ids) > 0) {
            $helpingUsers = User::with('image')
                ->whereIn('id', $task->helping_user_ids)
                ->get()
                ->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null
                    ];
                })
                ->toArray();
        }

        return response()->json([
            'success' => true,
            'message' => 'Tâche créée avec succès',
            'task' => [
                'id' => $task->id,
                'client_id' => $task->client_id,
                'task_name' => $task->task_name,
                'task_type' => $task->task_type,
                'description' => $task->description,
                'status' => $task->status,
                'urgent' => $task->urgent,
                'task_date' => $task->task_date,
                'started_at' => $task->started_at,
                'finished_at' => $task->finished_at,
                'technician_id' => $task->technician_id,
                'technician_name' => $task->technician ? $task->technician->first_name . ' ' . $task->technician->last_name : null,
                'technician_image' => $task->technician && $task->technician->image ? asset('storage/' . $task->technician->image->image_name) : null,
                'helping_user_ids' => $task->helping_user_ids ?? [],
                'helping_users' => $helpingUsers,
                'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
                'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                'client_image' => $task->client && $task->client->image ? $task->client->image->image_name : null,
                'task_products' => [],
                'has_ongoing_visit' => (bool)($task->has_ongoing_visit ?? false),
                'current_visit_status' => $task->getCurrentVisitStatus()
            ]
        ]);
    }

    // Return tasks for a given client (JSON) for mobile client detail
    public function clientTasks(Request $request)
    {
        $request->validate(['client_id' => 'required|integer']);

        $query = Task::with(['client.city', 'client.image', 'technician.image', 'services', 'events.user.image'])
            ->where('client_id', $request->client_id)
            ->orderByDesc('task_date');

        $tasks = $query->paginate(10);

        return response()->json([
            'tasks' => $tasks->map(function ($task) {
                // Get helping users (not a proper relationship, so we fetch it separately)
                $helpingUsers = $task->helpingUsers();
                
                return [
                    'id' => $task->id,
                    'task_name' => $task->task_name,
                    'task_type' => $task->task_type,
                    'status' => $task->getComputedStatus(),
                    'task_date' => optional($task->task_date)->toDateString() ?? (string)$task->task_date,
                    'description' => $task->description,
                    'urgent' => $task->urgent,
                    'is_paid' => (bool) ($task->is_paid ?? false),
                    'amount_paid' => $task->amount_paid !== null ? (float) $task->amount_paid : null,
                    'admin_delivery_amount' => $task->admin_delivery_amount !== null ? (float) $task->admin_delivery_amount : null,
                    'admin_delivery_task_id' => $task->admin_delivery_task_id,
                    'hourly_rate' => $task->hourly_rate !== null ? (float) $task->hourly_rate : null,
                    'cancellation_reason' => $task->cancellation_reason,
                    'client_id' => $task->client_id,
                    'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
                    'client_image' => $task->client && $task->client->image ? $task->client->image->image_name : null,
                    'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                    'technician' => $task->technician ? [
                        'id' => $task->technician->id,
                        'first_name' => $task->technician->first_name,
                        'last_name' => $task->technician->last_name,
                        'full_name' => $task->technician->first_name . ' ' . $task->technician->last_name,
                        'name' => $task->technician->first_name . ' ' . $task->technician->last_name,
                        'image' => $task->technician->image ? $task->technician->image->image_name : null
                    ] : null,
                    'helping_users' => $helpingUsers->map(function ($user) {
                        return [
                            'id' => $user->id,
                            'first_name' => $user->first_name,
                            'last_name' => $user->last_name,
                            'name' => $user->first_name . ' ' . $user->last_name,
                            'image' => $user->image ? $user->image->image_name : null
                        ];
                    }),
                    'services' => $task->services->map(function ($service) {
                        return [
                            'id' => $service->id,
                            'name' => $service->name,
                            'description' => $service->description,
                            'price' => $service->price
                        ];
                    }),
                    'events' => $task->events->map(function ($event) {
                        return [
                            'id' => $event->id,
                            'type' => $event->event_type,
                            'formatted_time' => $event->created_at->format('d/m/Y à H:i'),
                            'user_name' => $event->user ? $event->user->first_name . ' ' . $event->user->last_name : null,
                            'user_image' => $event->user && $event->user->image ? $event->user->image->image_name : null
                        ];
                    })
                ];
            }),
            'pagination' => [
                'current_page' => $tasks->currentPage(),
                'last_page' => $tasks->lastPage(),
                'per_page' => $tasks->perPage(),
                'total' => $tasks->total(),
                'from' => $tasks->firstItem(),
                'to' => $tasks->lastItem()
            ]
        ]);
    }

    public function startTask(Task $task)
    {
        // Check if user is authorized to start this task
        if ($task->technician_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à démarrer cette tâche'
            ], 403);
        }

        // Check if task is already started
        if ($task->started_at) {
            return response()->json([
                'success' => false,
                'message' => 'Cette tâche est déjà démarrée'
            ], 400);
        }

        // Start the task
        $task->update([
            'status' => 'en cours',
            'started_at' => Carbon::now()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tâche démarrée avec succès',
            'started_at' => $task->started_at->toISOString()
        ]);
    }


    public function usersWithTasks(Request $request)
    {
        // Get date range for last 30 days (for task count calculation)
        $thirtyDaysAgo = Carbon::now()->subDays(30)->startOfDay();
        $today = Carbon::now()->endOfDay();
        
        // Get ALL users (excluding blocked users)
        $users = User::with([
            'image', 
            'profile',
            'leaveRequests' => function($q) {
                // Load active leave requests (accepted or on_leave status)
                $q->whereIn('status', ['accepted', 'on_leave'])
                  ->select('id', 'user_id', 'start_date', 'end_date', 'status');
            }
        ])
            ->where('is_blocked', false)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function($user) use ($thirtyDaysAgo, $today) {
                // Count tasks where user is main tech in last 30 days
                $mainTechTasks = $user->tasks()
                    ->whereBetween('task_date', [$thirtyDaysAgo, $today])
                    ->count();
                
                // Count tasks where user is helping in last 30 days
                $helpingTasks = Task::whereJsonContains('helping_user_ids', $user->id)
                    ->whereBetween('task_date', [$thirtyDaysAgo, $today])
                    ->count();
                
                // Get the last event for this user (ordered by id desc to get most recent)
                $lastEvent = \App\Models\TaskEvent::where('user_id', $user->id)
                    ->orderBy('id', 'desc')
                    ->first();
                
                // Determine status based on last event
                $status = 'waiting'; // Default: gray
                if ($lastEvent) {
                    switch ($lastEvent->event_type) {
                        case 'start_visit':
                        case 'resume_visit':
                            $status = 'working'; // Yellow
                            break;
                        case 'pause_visit':
                            $status = 'paused'; // Green
                            break;
                        case 'start_route':
                            $status = 'route'; // Orange
                            break;
                        default:
                            $status = 'waiting'; // Gray (end_route, finish_visit, etc.)
                            break;
                    }
                }
                
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'image' => $user->image && $user->image->image_name 
                        ? asset('storage/' . $user->image->image_name)
                        : null,
                    'profile' => $user->profile ? $user->profile->profile_name : 'Non défini',
                    'profile_id' => $user->profile_id,
                    'tasks_count' => $mainTechTasks + $helpingTasks,
                    'last_event_status' => $status,
                    'leave_requests' => $user->leaveRequests->map(function($leave) {
                        return [
                            'start_date' => $leave->start_date->format('Y-m-d'),
                            'end_date' => $leave->end_date->format('Y-m-d')
                        ];
                    })
                ];
            })
            ->values(); // Re-index the array

        return response()->json($users);
    }

    public function tracking(Request $request, $userId)
    {
        // Get date parameter (default to today)
        $date = $request->get('date', Carbon::now()->format('Y-m-d'));

        // Get all events by this user on this date (including deployment events)
        // Using whereDate to compare only the date part, avoiding timezone issues
        $userEvents = \App\Models\TaskEvent::where('user_id', $userId)
            ->whereDate('event_time', $date)
            ->with(['task.client.city', 'task.client.image', 'user.image', 'city'])
            ->get();

        // Build tracking events directly from user events
        $tracking = [];
        foreach ($userEvents as $event) {
            $eventTime = Carbon::parse($event->event_time);
            
            // Handle deployment events (no task associated)
            if (in_array($event->event_type, ['start_deployment', 'finish_deployment'])) {
                $tracking[] = [
                    'id' => 'deployment_' . $event->event_type . '_' . $event->id,
                    'event_type' => $event->event_type,
                    'time' => $eventTime->format('H:i'),
                    'formatted_time' => $eventTime->format('d/m/Y H:i'),
                    'timestamp' => $eventTime->timestamp,
                    'task_name' => null,
                    'task_type' => null,
                    'status' => null,
                    'original_status' => null,
                    'client_name' => null,
                    'client_city' => null,
                    'client_image' => null,
                    'has_ongoing_visit' => false,
                    'user_id' => $event->user_id,
                    'user_name' => $event->user ? $event->user->first_name . ' ' . $event->user->last_name : null,
                    'user_image' => $event->user && $event->user->image && $event->user->image->image_name 
                        ? asset('storage/' . $event->user->image->image_name) 
                        : null,
                    'city_name' => $event->city ? $event->city->name : null
                ];
            } else {
                // Handle task-related events
                $task = $event->task;
                if (!$task) continue; // Skip if task was deleted

                $tracking[] = [
                    'id' => $task->id . '_' . $event->event_type . '_' . $event->id,
                    'event_type' => $event->event_type,
                    'time' => $eventTime->format('H:i'),
                    'formatted_time' => $eventTime->format('d/m/Y H:i'),
                    'timestamp' => $eventTime->timestamp,
                    'task_name' => $task->task_name,
                    'task_type' => $task->task_type,
                    'status' => $task->getComputedStatus(),
                    'original_status' => $task->status,
                    'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
                    'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                    'client_image' => $task->client && $task->client->image && $task->client->image->image_name 
                        ? asset('storage/' . $task->client->image->image_name) 
                        : 'https://ui-avatars.com/api/?name=' . urlencode($task->client ? $task->client->first_name . ' ' . $task->client->last_name : 'Client') . '&background=4F46E5&color=fff',
                    'has_ongoing_visit' => (bool) ($task->has_ongoing_visit ?? false),
                    'user_id' => $event->user_id,
                    'user_name' => $event->user ? $event->user->first_name . ' ' . $event->user->last_name : null,
                    'user_image' => $event->user && $event->user->image && $event->user->image->image_name 
                        ? asset('storage/' . $event->user->image->image_name) 
                        : null,
                    'city_name' => null
                ];
            }
        }

        $typeOrder = [
            'start_deployment' => 1,
            'start_visit' => 2,
            'finish_visit' => 3,
            'finish_task' => 4,
            'finish_deployment' => 5
        ];

        usort($tracking, function($a, $b) use ($typeOrder) {
            if ($a['timestamp'] === $b['timestamp']) {
                $orderA = $typeOrder[$a['event_type']] ?? 99;
                $orderB = $typeOrder[$b['event_type']] ?? 99;
                return $orderA <=> $orderB;
            }
            return $a['timestamp'] <=> $b['timestamp'];
        });

        // Debug information
        return response()->json([
            'success' => true,
            'tracking' => $tracking
        ]);
    }

    // Event-based task management methods
    public function startVisit(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);
        
        // Check if user is main technician, helping user, or has admin permission
        $userId = Auth::id();
        $isMainTechnician = $task->technician_id === $userId;
        $isHelpingUser = is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids);
        $hasAdminPermission = Auth::user()->profile && Auth::user()->profile->permissions->pluck('code')->contains('mobile_admin_tasks');
        
        if (!$isMainTechnician && !$isHelpingUser && !$hasAdminPermission) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        // Check if task is not already finished
        if ($task->isTaskFinished()) {
            return response()->json(['success' => false, 'message' => 'Tâche déjà terminée'], 400);
        }

        // Check if current user already has an active visit on THIS task
        if ($task->hasUserActiveVisit(Auth::id())) {
            return response()->json([
                'success' => false, 
                'message' => 'Vous avez déjà une visite en cours sur cette tâche'
            ], 400);
        }

        // Check if user has any active state (working, route, or paused) in other tasks
        if (Task::userHasActiveStateInOtherTasks(Auth::id(), $taskId)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà une tâche en cours, en route ou en pause. Veuillez terminer ou annuler cette tâche avant de démarrer une nouvelle visite.'
            ], 400);
        }

        $event = $task->startVisit(Auth::id());
        
        // Reload task with events to ensure we have latest data
        $task = $task->fresh(['events']);

        return response()->json([
            'success' => true,
            'message' => 'Visite démarrée',
            'event' => $event,
            'task_status' => $task->status,
            'has_ongoing_visit' => $task->has_ongoing_visit,
            'current_user_has_active_visit' => $task->hasUserActiveVisit(Auth::id()),
            'current_user_is_en_route' => $task->isUserEnRoute(Auth::id()),
            'current_visit_status' => $task->getCurrentVisitStatus()
        ]);
    }

    public function finishVisit(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);
        
        // Check if user is main technician, helping user, or has admin permission
        $userId = Auth::id();
        $isMainTechnician = $task->technician_id === $userId;
        $isHelpingUser = is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids);
        $hasAdminPermission = Auth::user()->profile && Auth::user()->profile->permissions->pluck('code')->contains('mobile_admin_tasks');
        
        if (!$isMainTechnician && !$isHelpingUser && !$hasAdminPermission) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        // Check if current user has an active visit to finish
        $hasActiveVisit = $task->hasUserActiveVisit(Auth::id());
        \Log::info('finishVisit validation', [
            'user_id' => Auth::id(),
            'task_id' => $taskId,
            'hasUserActiveVisit' => $hasActiveVisit
        ]);
        
        if (!$hasActiveVisit) {
            return response()->json([
                'success' => false, 
                'message' => 'Vous n\'avez pas de visite en cours sur cette tâche'
            ], 400);
        }

        $event = $task->finishVisit(Auth::id());
        
        // Reload task with events to ensure we have latest data
        $task = $task->fresh(['events']);

            return response()->json([
            'success' => true,
            'message' => 'Visite terminée',
            'event' => $event,
            'task_status' => $task->status,
            'has_ongoing_visit' => $task->has_ongoing_visit,
            'current_user_has_active_visit' => $task->hasUserActiveVisit(Auth::id()),
            'current_user_is_en_route' => $task->isUserEnRoute(Auth::id()),
            'current_visit_status' => $task->getCurrentVisitStatus()
        ]);
    }

    public function pauseVisit(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);
        
        // Check if user is main technician, helping user, or has admin permission
        $userId = Auth::id();
        $isMainTechnician = $task->technician_id === $userId;
        $isHelpingUser = is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids);
        $hasAdminPermission = Auth::user()->profile && Auth::user()->profile->permissions->pluck('code')->contains('mobile_admin_tasks');
        
        if (!$isMainTechnician && !$isHelpingUser && !$hasAdminPermission) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        // Check if task is not already finished
        if ($task->isTaskFinished()) {
            return response()->json(['success' => false, 'message' => 'Tâche déjà terminée'], 400);
        }

        // Check if current user is working (has active visit)
        $currentState = $task->getUserCurrentState($userId);
        if ($currentState !== 'working') {
            return response()->json([
                'success' => false, 
                'message' => 'Vous devez avoir une visite en cours pour la mettre en pause'
            ], 400);
        }

        $event = $task->pauseVisit($userId);
        
        // Reload task with events to ensure we have latest data
        $task = $task->fresh(['events']);

        return response()->json([
            'success' => true,
            'message' => 'Visite mise en pause',
            'event' => $event,
            'task_status' => $task->status,
            'user_last_event' => $task->getUserLastEvent($userId)?->event_type
        ]);
    }

    public function resumeVisit(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);
        
        // Check if user is main technician, helping user, or has admin permission
        $userId = Auth::id();
        $isMainTechnician = $task->technician_id === $userId;
        $isHelpingUser = is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids);
        $hasAdminPermission = Auth::user()->profile && Auth::user()->profile->permissions->pluck('code')->contains('mobile_admin_tasks');
        
        if (!$isMainTechnician && !$isHelpingUser && !$hasAdminPermission) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        // Check if task is not already finished
        if ($task->isTaskFinished()) {
            return response()->json(['success' => false, 'message' => 'Tâche déjà terminée'], 400);
        }

        // Check if current user is paused
        $currentState = $task->getUserCurrentState($userId);
        if ($currentState !== 'paused') {
            return response()->json([
                'success' => false, 
                'message' => 'Vous n\'avez pas de visite en pause'
            ], 400);
        }

        // Block resume if user has another task en route or en cours (same rule as start visit)
        if (Task::userHasActiveStateInOtherTasks($userId, (int) $taskId)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez déjà une tâche en cours ou en route. Veuillez terminer ou annuler cette tâche avant de reprendre une visite.'
            ], 400);
        }

        $event = $task->resumeVisit($userId);
        
        // Reload task with events to ensure we have latest data
        $task = $task->fresh(['events']);

        return response()->json([
            'success' => true,
            'message' => 'Visite reprise',
            'event' => $event,
            'task_status' => $task->status,
            'user_last_event' => $task->getUserLastEvent($userId)?->event_type
        ]);
    }

    public function finishTask(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);
        
        // Only the main technician can finish tasks
        if ($task->technician_id !== Auth::id()) {
            return response()->json(['success' => false, 'message' => 'Seul le technicien principal peut terminer la tâche'], 403);
        }

        // Check if task is not already finished
        if ($task->isTaskFinished()) {
            return response()->json(['success' => false, 'message' => 'Tâche déjà terminée'], 400);
        }

        // Delivery tasks require "who received the payment" before finishing
        if ($task->isAdminDeliveryTask()) {
            $receivedByUserId = $request->input('received_by_user_id');
            if ($receivedByUserId === null || $receivedByUserId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Veuillez sélectionner la personne qui a reçu le paiement.',
                ], 422);
            }
            $receivedByUserId = (int) $receivedByUserId;
            $request->merge(['received_by_user_id' => $receivedByUserId]);
            $validated = $request->validate([
                'received_by_user_id' => 'required|integer|exists:users,id',
            ], [
                'received_by_user_id.required' => 'Veuillez sélectionner la personne qui a reçu le paiement.',
                'received_by_user_id.exists' => 'L\'utilisateur sélectionné n\'existe pas.',
            ]);
            $task->update(['admin_delivery_received_by_user_id' => $validated['received_by_user_id']]);
        }

        // Check if current user has an active visit
        if ($task->hasUserActiveVisit(Auth::id())) {
            return response()->json([
                'success' => false, 
                'message' => 'Veuillez terminer votre visite en cours avant de terminer la tâche'
            ], 400);
        }

        // Check if current user is en route
        $currentUserState = $task->getUserCurrentState(Auth::id());
        if ($currentUserState === 'route') {
            return response()->json([
                'success' => false, 
                'message' => 'Veuillez terminer votre trajet en cours avant de terminer la tâche'
            ], 400);
        }

        // Check if current user is en pause
        if ($currentUserState === 'paused') {
            return response()->json([
                'success' => false, 
                'message' => 'Veuillez reprendre ou terminer votre visite en pause avant de terminer la tâche'
            ], 400);
        }

        // Check if any other user has an active visit
        if ($task->hasAnyOtherUserActiveVisit(Auth::id())) {
            $usersWithActiveVisits = $task->getUsersWithActiveVisitsDetails();
            $userNames = array_column($usersWithActiveVisits, 'name');
            
            return response()->json([
                'success' => false,
                'message' => 'Impossible de terminer la tâche. Les utilisateurs suivants ont encore des visites en cours: ' . implode(', ', $userNames),
                'users_with_active_visits' => $usersWithActiveVisits
            ], 400);
        }

        // Check if any user is en route
        $usersEnRoute = $task->getUsersEnRouteDetails();
        if (!empty($usersEnRoute)) {
            $userNames = array_column($usersEnRoute, 'name');
            
            return response()->json([
                'success' => false,
                'message' => 'Impossible de terminer la tâche. Les utilisateurs suivants sont en route: ' . implode(', ', $userNames),
                'users_en_route' => $usersEnRoute
            ], 400);
        }

        // Check if any user is en pause
        $usersEnPause = $task->getUsersEnPauseDetails();
        if (!empty($usersEnPause)) {
            $userNames = array_column($usersEnPause, 'name');
            
            return response()->json([
                'success' => false,
                'message' => 'Impossible de terminer la tâche. Les utilisateurs suivants sont en pause: ' . implode(', ', $userNames),
                'users_en_pause' => $usersEnPause
            ], 400);
        }

        $event = $task->finishTask(Auth::id());
        
        // Reload task with events to ensure we have latest data
        $task = $task->fresh(['events']);

        return response()->json([
            'success' => true,
            'message' => 'Tâche terminée',
            'event' => $event,
            'task_status' => $task->status,
            'has_ongoing_visit' => $task->has_ongoing_visit,
            'current_user_has_active_visit' => $task->hasUserActiveVisit(Auth::id()),
            'current_visit_status' => $task->getCurrentVisitStatus()
        ]);
    }

    public function cancelTask(Request $request, $taskId)
    {
        $request->validate([
            'reason' => 'required|string|min:3'
        ]);

        $task = Task::findOrFail($taskId);
        
        // Check if user has permission to cancel this task
        $userPermissions = Auth::user()->profile->permissions->pluck('code');
        if ($task->technician_id !== Auth::id() && 
            !$userPermissions->contains('mobile_tasks_write') && 
            !$userPermissions->contains('mobile_admin_tasks')) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        // Check if task is not already finished or cancelled
        if ($task->status === 'terminée' || $task->status === 'annulée') {
            return response()->json(['success' => false, 'message' => 'Cette tâche ne peut pas être annulée'], 400);
        }

        $task->cancelTask($request->input('reason'), Auth::id());

        return response()->json([
            'success' => true,
            'message' => 'Tâche annulée avec succès',
            'task_status' => $task->fresh()->status,
            'has_ongoing_visit' => (bool) ($task->fresh()->has_ongoing_visit ?? false),
            'current_visit_status' => $task->fresh()->getCurrentVisitStatus(),
            'cancellation_reason' => $task->fresh()->cancellation_reason,
            'canceled_at' => $task->fresh()->canceled_at
        ]);
    }

    public function updateDescription(Request $request, $taskId)
    {
        $request->validate([
            'description' => 'nullable|string|max:1000'
        ]);

        $task = Task::findOrFail($taskId);
        
        // Check if user has permission to update this task
        $userPermissions = Auth::user()->profile->permissions->pluck('code');
        if ($task->technician_id !== Auth::id() && 
            !$userPermissions->contains('mobile_tasks_write') && 
            !$userPermissions->contains('mobile_admin_tasks')) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $task->update([
            'description' => $request->input('description')
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Description mise à jour avec succès',
            'description' => $task->description
        ]);
    }

    public function update(Request $request, Task $task)
    {
        $validated = $request->validate([
            'task_name' => 'required|string|max:255',
            'task_type' => 'required|string',
            'task_date' => 'required|date',
            'client_id' => 'nullable|exists:clients,id',
            'technician_id' => 'required|exists:users,id',
            'helping_user_ids' => 'nullable|array',
            'helping_user_ids.*' => 'exists:users,id'
        ]);

        // Check permission
        $userPermissions = Auth::user()->profile->permissions->pluck('code');
        if ($task->technician_id !== Auth::id() && 
            !$userPermissions->contains('mobile_tasks_write') && 
            !$userPermissions->contains('mobile_admin_tasks')) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        // Update task
        $task->update([
            'task_name' => $validated['task_name'],
            'task_type' => $validated['task_type'],
            'task_date' => $validated['task_date'],
            'client_id' => $validated['client_id'],
            'technician_id' => $validated['technician_id'],
            'helping_user_ids' => $validated['helping_user_ids'] ?? []
        ]);

        // Load updated relationships
        $task->load(['technician.image', 'client.image', 'client.city']);

        // Map helping users
        $helpingUsers = [];
        if ($task->helping_user_ids && is_array($task->helping_user_ids)) {
            $users = \App\Models\User::whereIn('id', $task->helping_user_ids)->with('image')->get();
            $helpingUsers = $users->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null
                ];
            })->toArray();
        }

        return response()->json([
            'success' => true,
            'message' => 'Tâche mise à jour avec succès',
            'task' => [
                'id' => $task->id,
                'task_name' => $task->task_name,
                'task_type' => $task->task_type,
                'task_date' => $task->task_date,
                'client_id' => $task->client_id,
                'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
                'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                'client_image' => $task->client && $task->client->image ? asset('storage/' . $task->client->image->image_name) : null,
                'technician_id' => $task->technician_id,
                'technician_name' => $task->technician ? $task->technician->first_name . ' ' . $task->technician->last_name : null,
                'technician_image' => $task->technician && $task->technician->image ? asset('storage/' . $task->technician->image->image_name) : null,
                'helping_user_ids' => $task->helping_user_ids ?? [],
                'helping_users' => $helpingUsers
            ]
        ]);
    }

    public function getTaskEvents(Request $request, $taskId)
    {
        $task = Task::with(['events.user.image', 'adminDeliveryReceivedByUser'])->findOrFail($taskId);
        
        // Check if user has permission to view this task
        $userPermissions = Auth::user()->profile->permissions->pluck('code');
        if ($task->technician_id !== Auth::id() && 
            !$userPermissions->contains('mobile_tasks_write') && 
            !$userPermissions->contains('mobile_admin_tasks')) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        $events = $task->events->map(function($event) {
            return [
                'id' => $event->id,
                'type' => $event->event_type,
                'time' => $event->event_time->format('H:i'),
                'date' => $event->event_time->format('Y-m-d'),
                'timestamp' => $event->event_time->timestamp,
                'formatted_time' => $event->event_time->format('d/m/Y H:i'),
                'user_id' => $event->user_id,
                'user_name' => $event->user ? $event->user->first_name . ' ' . $event->user->last_name : null,
                'user_image' => $event->user && $event->user->image && $event->user->image->image_name 
                    ? asset('storage/' . $event->user->image->image_name) 
                    : null
            ];
        });
        
        // Get pending service propositions for this task
        $servicePropositions = \App\Models\ServiceProposition::with('proposer')
            ->where('task_id', $taskId)
            ->where('status', 'pending')
            ->get()
            ->map(function($proposition) {
                return [
                    'id' => $proposition->id,
                    'name' => $proposition->name,
                    'proposed_by_name' => $proposition->proposer ? $proposition->proposer->first_name . ' ' . $proposition->proposer->last_name : 'Unknown',
                    'created_at' => $proposition->created_at->format('d/m/Y H:i')
                ];
            });

        $warrantyProducts = WarrantyService::getActiveWarrantyProducts($task->client, 10);

        $userId = Auth::id();

        return response()->json([
            'success' => true,
            'events' => $events,
            'task_status' => $task->status,
            'has_ongoing_visit' => (bool) ($task->has_ongoing_visit ?? false),
            'current_status' => $task->getCurrentVisitStatus(),
            'current_user_has_active_visit' => $task->hasUserActiveVisit($userId),
            'current_user_is_en_route' => $task->isUserEnRoute($userId),
            'user_last_event' => $task->getUserLastEvent($userId)?->event_type,
            'technician_id' => $task->technician_id,
            'cancellation_reason' => $task->cancellation_reason,
            'canceled_at' => $task->canceled_at,
            'is_paid' => (bool) ($task->is_paid ?? false),
            'amount_paid' => $task->amount_paid !== null ? (float) $task->amount_paid : null,
            'admin_delivery_amount' => $task->admin_delivery_amount !== null ? (float) $task->admin_delivery_amount : null,
            'admin_delivery_task_id' => $task->admin_delivery_task_id,
            'admin_delivery_received_by_user_id' => $task->admin_delivery_received_by_user_id,
            'admin_delivery_received_by_user_name' => $task->adminDeliveryReceivedByUser ? $task->adminDeliveryReceivedByUser->first_name . ' ' . $task->adminDeliveryReceivedByUser->last_name : null,
            'service_propositions' => $servicePropositions,
            'warranty_products' => $warrantyProducts,
        ]);
    }

    public function getPastTasks()
    {
        try {
            // Get today's date (past tasks are from before today)
            $today = Carbon::today()->format('Y-m-d');
            
            \Log::info('Getting past tasks for user: ' . Auth::id() . ', today: ' . $today);
            
            // Fetch past tasks where user is either main technician or helping user
            $pastTasks = Task::with(['client.image', 'client.city', 'technician.image', 'services', 'adminDeliveryReceivedByUser'])
                ->where(function($query) {
                    $query->where('technician_id', Auth::id())
                          ->orWhereJsonContains('helping_user_ids', Auth::id());
                })
                ->where('task_date', '<', $today)
                ->whereNotIn('status', ['terminée', 'annulée'])
                ->orderBy('task_date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function($task) {
                    $userId = Auth::id();
                    $isMainTechnician = $task->technician_id == $userId;
                    $isHelpingUser = is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids);
                    
                    // Get helping users data
                    $helpingUsers = [];
                    if (is_array($task->helping_user_ids) && count($task->helping_user_ids) > 0) {
                        $helpingUsers = User::with('image')
                            ->whereIn('id', $task->helping_user_ids)
                            ->get()
                            ->map(function($user) {
                                return [
                                    'id' => $user->id,
                                    'name' => $user->first_name . ' ' . $user->last_name,
                                    'image' => $user->image && $user->image->image_name ? asset('storage/' . $user->image->image_name) : null
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
                        'admin_delivery_received_by_user_id' => $task->admin_delivery_received_by_user_id,
                        'admin_delivery_received_by_user_name' => $task->adminDeliveryReceivedByUser ? $task->adminDeliveryReceivedByUser->first_name . ' ' . $task->adminDeliveryReceivedByUser->last_name : null,
                        'hourly_rate' => $task->hourly_rate !== null ? (float) $task->hourly_rate : null,
                        'technician' => $task->technician ? [
                            'id' => $task->technician->id,
                            'name' => $task->technician->first_name . ' ' . $task->technician->last_name,
                            'image' => $task->technician->image && $task->technician->image->image_name ? asset('storage/' . $task->technician->image->image_name) : null
                        ] : null,
                        'helping_users' => $helpingUsers,
                        'client_name' => $task->client ? $task->client->first_name . ' ' . $task->client->last_name : null,
                        'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
                        'client_image' => $task->client && $task->client->image ? $task->client->image->image_name : null,
                    ];
                });

            \Log::info('Found ' . $pastTasks->count() . ' past tasks');

            return response()->json([
                'success' => true,
                'past_tasks' => $pastTasks,
                'count' => $pastTasks->count()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in getPastTasks: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching past tasks: ' . $e->getMessage(),
                'past_tasks' => [],
                'count' => 0
            ], 500);
        }
    }

    public function startDeployment(Request $request)
    {
        $request->validate([
            'city_id' => 'required|exists:cities,id'
        ]);
        
        $user = Auth::user();
        
        // Check if already on deployment
        if ($user->is_on_deployment) {
            return response()->json([
                'success' => false,
                'message' => 'Vous êtes déjà en déplacement'
            ], 400);
        }
        
        // Update user status
        $user->is_on_deployment = true;
        $user->save();
        
        // Create deployment event (not tied to a specific task)
        \App\Models\TaskEvent::create([
            'task_id' => null, // No specific task
            'event_type' => 'start_deployment',
            'event_time' => now(),
            'user_id' => Auth::id(),
            'city_id' => $request->city_id
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Déplacement démarré',
            'is_on_deployment' => true
        ]);
    }

    public function finishDeployment()
    {
        $user = Auth::user();
        
        if (!$user->is_on_deployment) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun déplacement en cours'
            ], 400);
        }
        
        // Update user status
        $user->is_on_deployment = false;
        $user->save();
        
        // Create deployment finish event
        \App\Models\TaskEvent::create([
            'task_id' => null,
            'event_type' => 'finish_deployment',
            'event_time' => now(),
            'user_id' => Auth::id()
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Déplacement terminé',
            'is_on_deployment' => false
        ]);
    }

    /**
     * Show the propose task page with list of user's proposals
     */
    public function showProposeForm()
    {
        // Check permission
        if (!Auth::user()->profile || !Auth::user()->profile->permissions->pluck('code')->contains('mobile_tasks_propose')) {
            abort(403, 'Unauthorized');
        }

        // Get user's proposed tasks (all statuses)
        $proposedTasks = ProposedTask::with(['client.image', 'client.city'])
            ->where('proposed_by', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($proposal) {
                return [
                    'id' => $proposal->id,
                    'task_name' => $proposal->task_name,
                    'task_type' => $proposal->task_type,
                    'description' => $proposal->description,
                    'urgent' => $proposal->urgent,
                    'status' => $proposal->status ?? 'en attente',
                    'created_at' => $proposal->created_at->format('d/m/Y H:i'),
                    'client_name' => $proposal->client ? $proposal->client->first_name . ' ' . $proposal->client->last_name : null,
                    'client_image' => $proposal->client && $proposal->client->image ? asset('storage/' . $proposal->client->image->image_name) : null,
                    'client_city' => $proposal->client && $proposal->client->city ? $proposal->client->city->name : null,
                ];
            });

        // Fetch clients for the dropdown
        $clients = Client::with(['image', 'city'])
            ->orderBy('first_name')
            ->get()
            ->map(function($client) {
                return [
                    'id' => $client->id,
                    'name' => $client->first_name . ' ' . $client->last_name,
                    'image' => $client->image ? asset('storage/' . $client->image->image_name) : null,
                    'city' => $client->city ? $client->city->name : null
                ];
            });

        // Server-render the task types so the dropdown is never empty on first paint.
        $taskTypes = TaskType::orderBy('name')->get(['id', 'name']);

        return view('mobile.propose-task', compact('clients', 'proposedTasks', 'taskTypes'));
    }

    /**
     * Store a new proposed task
     */
    public function storeProposal(Request $request)
    {
        \Log::info('Propose task request received', ['data' => $request->all()]);

        // Check permission
        if (!Auth::user()->profile || !Auth::user()->profile->permissions->pluck('code')->contains('mobile_tasks_propose')) {
            \Log::warning('Permission denied for user', ['user_id' => Auth::id()]);
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé'
            ], 403);
        }

        try {
            $validated = $request->validate([
                'task_name' => 'required|string|max:255',
                'task_type' => 'required|string',
                'client_id' => 'nullable|exists:clients,id',
                'description' => 'required_if:client_id,null|nullable|string',
                'urgent' => 'boolean'
            ]);
            
            \Log::info('Validation passed', ['validated' => $validated]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation failed', ['errors' => $e->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors()
            ], 422);
        }

        try {
            // Create the proposed task
            $proposedTask = ProposedTask::create([
                'task_name' => $validated['task_name'],
                'task_type' => $validated['task_type'],
                'client_id' => $validated['client_id'] ?? null,
                'description' => $validated['description'] ?? '',
                'proposed_by' => Auth::id(),
                'urgent' => $validated['urgent'] ?? false,
                'status' => 'en attente'
            ]);

            \Log::info('Proposed task created successfully', ['id' => $proposedTask->id]);

            // Get proposer name
            $proposer = Auth::user();
            $proposerName = $proposer->first_name . ' ' . $proposer->last_name;

            // Get client name if exists
            $clientName = null;
            if ($proposedTask->client_id) {
                $client = Client::find($proposedTask->client_id);
                $clientName = $client ? $client->first_name . ' ' . $client->last_name : null;
            }

            // Get admin users with both tasks_view_all and tasks_write permissions (exclude profile_id 1)
            $adminUsers = User::where('profile_id', '!=', 1)
                ->whereHas('profile.permissions', function($query) {
                    $query->where('code', 'tasks_view_all');
                })
                ->whereHas('profile.permissions', function($query) {
                    $query->where('code', 'tasks_write');
                })
                ->get();

            // Send email notifications to admins
            foreach ($adminUsers as $admin) {
                if ($admin->email) {
                    Mail::to($admin->email)->send(new TaskProposedMail($proposedTask, $proposerName, $clientName));
                }
            }

            // Create web notifications for admins
            $notificationService = app(NotificationService::class);
            foreach ($adminUsers as $admin) {
                $notificationService->sendToUser(
                    $admin->id,
                    'Nouvelle proposition de tâche',
                    $proposerName . ' a proposé une nouvelle tâche: ' . $proposedTask->task_name,
                    ['type' => 'task_proposed', 'proposed_task_id' => $proposedTask->id]
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Votre proposition de tâche a été envoyée avec succès',
                'proposal' => [
                    'id' => $proposedTask->id,
                    'task_name' => $proposedTask->task_name,
                    'task_type' => $proposedTask->task_type,
                    'description' => $proposedTask->description,
                    'urgent' => $proposedTask->urgent,
                    'status' => $proposedTask->status ?? 'en attente',
                    'created_at' => $proposedTask->created_at->format('d/m/Y H:i'),
                    'client_name' => $clientName,
                    'client_image' => $proposedTask->client && $proposedTask->client->image ? asset('storage/' . $proposedTask->client->image->image_name) : null,
                    'client_city' => $proposedTask->client && $proposedTask->client->city ? $proposedTask->client->city->name : null,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('Error creating proposed task: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la création de la proposition: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update services for a task
     */
    public function updateServices(Request $request, Task $task)
    {
        if ($task->technician_id !== Auth::id()) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le technicien principal peut gérer les services.',
            ], 403);
        }

        $validated = $request->validate([
            'service_ids' => 'nullable|array',
            'service_ids.*' => 'exists:services,id'
        ]);

        try {
            // Get service prices and create sync data
            $servicesWithPrice = [];
            if (!empty($validated['service_ids'])) {
                foreach ($validated['service_ids'] as $serviceId) {
                    $service = \App\Models\Service::find($serviceId);
                    if ($service) {
                        $servicesWithPrice[$serviceId] = ['price' => $service->price];
                    }
                }
            }
            
            // Sync services with the task including prices
            $task->services()->sync($servicesWithPrice);

            // Reload services with fresh data including pivot
            $task->load('services');

            $services = $task->services->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'description' => $service->description,
                    'price' => $service->pivot && $service->pivot->price !== null
                        ? (float) $service->pivot->price
                        : ($service->price !== null ? (float) $service->price : null),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Services mis à jour avec succès',
                'services' => $services,
                'total_services_price' => $task->getTotalServicesPrice()
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating task services: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour des services'
            ], 500);
        }
    }

    public function updatePayment(Request $request, Task $task)
    {
        $validated = $request->validate([
            'amount_paid' => 'required|numeric|min:0'
        ]);

        $user = Auth::user();
        $userPermissions = $user->profile ? $user->profile->permissions->pluck('code') : collect();
        $isMainTechnician = $task->technician_id === Auth::id();
        $canManage = $isMainTechnician
            || $userPermissions->contains('mobile_admin_tasks')
            || $userPermissions->contains('mobile_tasks_write');

        if (!$canManage) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorisé'
            ], 403);
        }

        try {
            $task->update([
                'amount_paid' => $validated['amount_paid'],
                'is_paid' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Paiement enregistré avec succès',
                'task' => [
                    'id' => $task->id,
                    'is_paid' => true,
                    'amount_paid' => $task->amount_paid !== null ? (float) $task->amount_paid : null,
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error updating task payment (mobile): ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement du paiement'
            ], 500);
        }
    }

    /**
     * Store admin delivery payment (mobile): same as admin panel — record amount on task and create linked delivery task (type id 5).
     * Main technician or user with mobile_admin_tasks permission can add it.
     */
    public function storeAdminDeliveryPayment(Request $request, Task $task)
    {
        $isMainTechnician = $task->technician_id === Auth::id();
        $hasAdminPermission = Auth::user()->profile && Auth::user()->profile->permissions->pluck('code')->contains('mobile_admin_tasks');

        if (!$isMainTechnician && !$hasAdminPermission) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le technicien principal ou un administrateur peut enregistrer un paiement à remettre.',
            ], 403);
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0',
            'delivery_date' => 'nullable|date',
        ]);

        $amount = (float) $validated['amount'];
        $appTz = config('app.timezone', 'Africa/Casablanca');
        $deliveryDate = !empty($validated['delivery_date'])
            ? Carbon::createFromFormat('Y-m-d', $validated['delivery_date'], $appTz)->toDateString()
            : Carbon::today($appTz)->toDateString();

        if ($task->admin_delivery_task_id) {
            return response()->json([
                'success' => false,
                'message' => 'Un paiement à remettre a déjà été enregistré pour cette tâche.',
            ], 422);
        }

        $taskType = TaskType::find(5);
        if (!$taskType) {
            return response()->json([
                'success' => false,
                'message' => 'Type de tâche (livraison administration) introuvable.',
            ], 500);
        }

        $clientName = '';
        if ($task->client) {
            $clientName = ' (Dr. ' . trim($task->client->first_name . ' ' . $task->client->last_name) . ')';
        }
        $taskName = "Remise paiement à l'administration" . $clientName;
        $description = "Tâche créée automatiquement pour la remise à l'administration du paiement de " . number_format($amount, 2, ',', ' ') . " DH perçu dans le cadre d'une autre tâche.";

        try {
            $deliveryTask = Task::create([
                'task_name' => $taskName,
                'task_type' => $taskType->name,
                'description' => $description,
                'status' => Task::STATUS_EN_ATTENTE,
                'urgent' => false,
                'task_date' => $deliveryDate,
                'technician_id' => $task->technician_id,
                'client_id' => null,
                'deployment_id' => null,
                'create_by' => auth()->id(),
                'helping_user_ids' => [],
            ]);

            $task->update([
                'admin_delivery_amount' => $amount,
                'admin_delivery_task_id' => $deliveryTask->id,
            ]);

            $task->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Paiement à remettre enregistré et tâche de livraison créée.',
                'task' => [
                    'id' => $task->id,
                    'admin_delivery_amount' => $task->admin_delivery_amount !== null ? (float) $task->admin_delivery_amount : null,
                    'admin_delivery_task_id' => $task->admin_delivery_task_id,
                ],
                'delivery_task_id' => $deliveryTask->id,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error storing admin delivery payment (mobile): ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'enregistrement du paiement à remettre.',
            ], 500);
        }
    }

    /**
     * Get user's last event for a task
     */
    public function getUserLastEventForTask(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);
        $userId = $request->get('user_id', Auth::id());
        $lastEvent = $task->getUserLastEventByTask($userId, $taskId);

        return response()->json([
            'success' => true,
            'last_event' => $lastEvent ? $lastEvent->event_type : null
        ]);
    }

    /**
     * User starts route (heading to task location)
     */
    public function startRoute(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);
        
        // Check if user is main technician, helping user, or has admin permission
        $userId = Auth::id();
        $isMainTechnician = $task->technician_id === $userId;
        $isHelpingUser = is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids);
        $hasAdminPermission = Auth::user()->profile && Auth::user()->profile->permissions->pluck('code')->contains('mobile_admin_tasks');
        
        if (!$isMainTechnician && !$isHelpingUser && !$hasAdminPermission) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        // Check if task is not finished
        $isTaskFinished = $task->isTaskFinished();
        $taskStatus = $task->status;
        
        if ($isTaskFinished || in_array($taskStatus, ['terminée', 'annulée'])) {
            return response()->json([
                'success' => false, 
                'message' => 'Tâche déjà terminée ou annulée',
                'task_status' => $taskStatus,
                'is_finished' => $isTaskFinished
            ], 400);
        }

        // Check if user is already en route
        $isEnRoute = $task->isUserEnRoute($userId);
        $currentState = $task->getUserCurrentState($userId);

        \Log::info('startRoute: User state check', [
            'user_id' => $userId,
            'task_name' => $task->task_name,
            'is_en_route' => $isEnRoute,
            'current_state' => $currentState
        ]);
        
        if ($isEnRoute) {
            return response()->json([
                'success' => false,
                'message' => 'Vous êtes déjà en route vers cette tâche',
                'current_state' => $currentState
            ], 400);
        }

        // Check if user has an active visit (they should finish it first)
        if ($currentState === 'working') {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez une visite en cours. Veuillez terminer la visite avant de démarrer un nouveau trajet.',
                'current_state' => $currentState
            ], 400);
        }

        // Check if user has any active state (working, route, or paused) in other tasks
        $activeStateInfo = Task::userHasActiveStateInOtherTasks($userId, $taskId);
        if ($activeStateInfo) {
            $message = '';
            $eventType = $activeStateInfo['event_type'];
            
            // Log for debugging
            \Log::info('startRoute: Active state found in other task', [
                'user_id' => $userId,
                'current_task_id' => $taskId,
                'active_state_info' => $activeStateInfo
            ]);
            
            if ($eventType === Task::EVENT_START_ROUTE) {
                $message = 'Vous êtes déjà en route vers une autre tâche ("' . $activeStateInfo['task_name'] . '"). Veuillez terminer ou annuler ce trajet avant de démarrer un nouveau trajet.';
            } elseif ($eventType === Task::EVENT_START_VISIT) {
                $message = 'Vous avez une visite en cours sur une autre tâche ("' . $activeStateInfo['task_name'] . '"). Veuillez terminer cette visite avant de démarrer un nouveau trajet.';
            } elseif ($eventType === Task::EVENT_PAUSE_VISIT) {
                $message = 'Vous avez une visite en pause sur une autre tâche ("' . $activeStateInfo['task_name'] . '"). Veuillez reprendre ou terminer cette visite avant de démarrer un nouveau trajet.';
            } else {
                $message = 'Vous avez déjà une tâche en cours, en route ou en pause ("' . $activeStateInfo['task_name'] . '"). Veuillez terminer ou annuler cette tâche avant de démarrer un nouveau trajet.';
            }
            
            return response()->json([
                'success' => false,
                'message' => $message,
                'active_state_info' => $activeStateInfo
            ], 400);
        }

        // Start route
        $event = $task->startRoute($userId);
        
        // Reload task with events - clear cache first
        $task->unsetRelation('events');
        $task->load('events');
        $task->refresh();

        return response()->json([
            'success' => true,
            'message' => 'En route vers la tâche',
            'event' => $event,
            'task_status' => $task->status,
            'current_user_is_en_route' => $task->isUserEnRoute($userId)
        ]);
    }

    /**
     * User ends route (cancels going to task location)
     */
    public function endRoute(Request $request, $taskId)
    {
        $task = Task::findOrFail($taskId);
        
        // Check if user is main technician, helping user, or has admin permission
        $userId = Auth::id();
        $isMainTechnician = $task->technician_id === $userId;
        $isHelpingUser = is_array($task->helping_user_ids) && in_array($userId, $task->helping_user_ids);
        $hasAdminPermission = Auth::user()->profile && Auth::user()->profile->permissions->pluck('code')->contains('mobile_admin_tasks');
        
        if (!$isMainTechnician && !$isHelpingUser && !$hasAdminPermission) {
            return response()->json(['success' => false, 'message' => 'Non autorisé'], 403);
        }

        // Check if task is not finished
        if ($task->isTaskFinished() || in_array($task->status, ['terminée', 'annulée'])) {
            return response()->json(['success' => false, 'message' => 'Tâche déjà terminée ou annulée'], 400);
        }

        // Check if user is currently en route
        if (!$task->isUserEnRoute($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas en route vers cette tâche'
            ], 400);
        }

        // End route
        $event = $task->endRoute($userId);
        
        // Reload task with events - clear cache first
        $task->unsetRelation('events');
        $task->load('events');
        $task->refresh();
    

        return response()->json([
            'success' => true,
            'message' => 'Trajet annulé',
            'event' => $event,
            'task_status' => $task->status,
        ]);
    }
}
