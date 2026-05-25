<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    // Event type constants
    const EVENT_START_ROUTE = 'start_route';
    const EVENT_END_ROUTE = 'end_route';
    const EVENT_FINISH_TASK = 'finish_task';
    const EVENT_CANCEL_TASK = 'cancel_task';
    const EVENT_START_VISIT = 'start_visit';
    const EVENT_FINISH_VISIT = 'finish_visit';
    const EVENT_PAUSE_VISIT = 'pause_visit';
    const EVENT_RESUME_VISIT = 'resume_visit';
    
    // Status constants
    const STATUS_EN_ATTENTE = 'en attente';
    const STATUS_EN_ROUTE = 'en route';
    const STATUS_EN_COURS = 'en cours';
    const STATUS_EN_PAUSE = 'en pause';
    const STATUS_TERMINEE = 'terminée';
    const STATUS_ANNULEE = 'annulée';

    protected $fillable = ['task_name', 'task_type', 'description', 'status', 'urgent', 'technician_id', 'create_by', 'client_id', 'deployment_id', 'task_date', 'observation', 'started_at', 'finished_at', 'has_ongoing_visit', 'canceled_at', 'cancellation_reason', 'helping_user_ids', 'is_paid', 'hourly_rate', 'amount_paid', 'incomplete_reason', 'admin_delivery_amount', 'admin_delivery_task_id', 'admin_delivery_received_by_user_id'];

    protected $casts = [
        'helping_user_ids' => 'array',
        'task_date' => 'date',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'canceled_at' => 'datetime',
        'is_paid' => 'boolean',
        'hourly_rate' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'admin_delivery_amount' => 'decimal:2',
    ];

    public function client() { return $this->belongsTo(Client::class); }
    public function technician() { return $this->belongsTo(User::class, 'technician_id'); }
    public function createdBy() { return $this->belongsTo(User::class, 'create_by'); }
    public function deployment() { return $this->belongsTo(Deployment::class); }
    public function adminDeliveryTask() { return $this->belongsTo(Task::class, 'admin_delivery_task_id'); }
    /** Source task that created this delivery task (the task that had the payment to remit). */
    public function sourceTaskForDelivery() { return $this->hasOne(Task::class, 'admin_delivery_task_id', 'id'); }
    public function adminDeliveryReceivedByUser() { return $this->belongsTo(User::class, 'admin_delivery_received_by_user_id'); }

    /**
     * Whether this task is an admin delivery task (type "Remise paiement à l'administration").
     */
    public function isAdminDeliveryTask(): bool
    {
        return $this->task_name && str_starts_with((string) $this->task_name, "Remise paiement à l'administration");
    }

    /**
     * Get helping users for this task
     */
    public function helpingUsers()
    {
        if (!$this->helping_user_ids || empty($this->helping_user_ids)) {
            return collect();
        }
        
        return User::whereIn('id', $this->helping_user_ids)->get();
    }
    public function taskProducts() { return $this->hasMany(TaskProduct::class); }
    public function services() { return $this->belongsToMany(Service::class, 'task_services')->withPivot('price'); }
    public function invoice() { return $this->hasOne(Invoice::class); }
    public function events() { return $this->hasMany(TaskEvent::class)->orderBy('event_time', 'asc'); }

    /**
     * Check if task has been started
     */
    public function isStarted()
    {
        return !is_null($this->started_at);
    }

    /**
     * Check if task has been finished
     */
    public function isFinished()
    {
        return !is_null($this->finished_at) || $this->status === 'annulée';
    }

    /**
     * Get task duration in minutes (if started and finished)
     */
    public function getDurationInMinutes()
    {
        if ($this->isStarted() && $this->isFinished()) {
            return $this->started_at->diffInMinutes($this->finished_at);
        }
        return null;
    }

    /**
     * Get task duration in hours (if started and finished)
     */
    public function getDurationInHours()
    {
        if ($this->isStarted() && $this->isFinished()) {
            return $this->started_at->diffInHours($this->finished_at);
        }
        return null;
    }

    // Event-based methods
    /**
     * Check if task has any visits started
     */
    public function hasVisitsStarted()
    {
        return $this->events()->where('event_type', 'start_visit')->exists();
    }

    /**
     * Check if task is finished (has finish_task event)
     */
    public function isTaskFinished()
    {
        return $this->events()->where('event_type', 'finish_task')->exists();
    }

    /**
     * Get current visit status (if there's an active visit)
     */
    public function getCurrentVisitStatus()
    {
        $hasAnyVisits = $this->events()->where('event_type', 'start_visit')->exists();
        
        if (!$hasAnyVisits) {
            return 'no_visit'; // No visit started
        }

        if ($this->has_ongoing_visit) {
            return 'visit_in_progress'; // Visit in progress
        }

        return 'visit_finished'; // Last visit is finished
    }

    /**
     * Get computed status based on events (uses new status hierarchy)
     */
    public function getComputedStatus()
    {
        return $this->computeTaskStatus();
    }

    /**
     * Compute task status based on all users' current states (hierarchical)
     */
    public function computeTaskStatus()
    {
        // If task is in a final state, return that
        if (in_array($this->status, [self::STATUS_TERMINEE, self::STATUS_ANNULEE])) {
            return $this->status;
        }

        // Get all users involved (main tech + helpers)
        $allUserIds = array_unique(array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        ));

        $hasWorkingUser = false;
        $hasRouteUser = false;
        $hasPausedUser = false;
        $hasStartedUser = false;

        foreach ($allUserIds as $userId) {
            $userState = $this->getUserCurrentState($userId);
            
            if ($userState === 'working') {
                $hasWorkingUser = true;
            } elseif ($userState === 'route') {
                $hasRouteUser = true;
            } elseif ($userState === 'paused') {
                $hasPausedUser = true;
                $hasStartedUser = true;
            }
            
            if (in_array($userState, ['working', 'paused'])) {
                $hasStartedUser = true;
            }
        }

        // Hierarchical status computation
        // Priority: working > route > paused > waiting
        if ($hasWorkingUser) {
            return self::STATUS_EN_COURS;
        }
        
        if ($hasRouteUser) {
            return self::STATUS_EN_ROUTE;
        }
        
        if ($hasPausedUser && $hasStartedUser) {
            return self::STATUS_EN_PAUSE;
        }

        return self::STATUS_EN_ATTENTE;
    }

    /**
     * Get a specific user's current state based on their last event
     * Returns: 'waiting', 'route', 'working', 'paused', or 'finished'
     */
    public function getUserCurrentState($userId)
    {
        // Get the last event for this user, ordered by id (most recent first)
        // Clear any existing order from the relationship first
        $lastEvent = $this->events()
            ->where('user_id', $userId)
            ->reorder('id', 'desc')
            ->first();
        
        if (!$lastEvent) {
            return 'waiting'; // No events yet
        }

        // Check the last event type to determine current state
        // If last event is start_route, start_visit, or pause_visit, user is still active
        switch ($lastEvent->event_type) {
            case self::EVENT_START_ROUTE:
                return 'route';
            
            case self::EVENT_END_ROUTE:
                return 'waiting';
            
            case self::EVENT_START_VISIT:
                return 'working';
            
            case self::EVENT_PAUSE_VISIT:
                return 'paused';
            
            case self::EVENT_RESUME_VISIT:
                return 'working';
            
            case self::EVENT_FINISH_VISIT:
                return 'waiting';

            case self::EVENT_FINISH_TASK:
                return 'finished';
            
            default:
                return 'waiting';
        }
    }

    /**
     * Check if a user can finish/mark incomplete the task
     * Only main technician can do this, and only if no other users are working
     */
    public function canUserFinishTask($userId)
    {
        // Must be main technician
        if ($this->technician_id !== $userId) {
            return false;
        }

        // Task must not be in final state already
        if (in_array($this->status, [self::STATUS_TERMINEE, self::STATUS_ANNULEE])) {
            return false;
        }

        // Check if any user is currently working
        $allUserIds = array_unique(array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        ));

        foreach ($allUserIds as $checkUserId) {
            $userState = $this->getUserCurrentState($checkUserId);
            if ($userState === 'working') {
                return false; // Someone is still working
            }
        }

        return true;
    }

    /**
     * Get details about which users are currently working
     */
    public function getUsersCurrentlyWorking()
    {
        $allUserIds = array_unique(array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        ));

        $workingUsers = [];
        
        foreach ($allUserIds as $userId) {
            if ($this->getUserCurrentState($userId) === 'working') {
                $workingUsers[] = $userId;
            }
        }

        return $workingUsers;
    }

    /**
     * Get details about users currently working with their names
     */
    public function getUsersCurrentlyWorkingDetails()
    {
        $userIds = $this->getUsersCurrentlyWorking();
        
        if (empty($userIds)) {
            return [];
        }
        
        return User::whereIn('id', $userIds)
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name
                ];
            })
            ->toArray();
    }

    /**
     * Start a new visit
     */
    public function startVisit($userId = null)
    {
        // Create the event
        $event = $this->events()->create([
            'event_type' => 'start_visit',
            'event_time' => now(),
            'user_id' => $userId
        ]);

        // Set has_ongoing_visit to true and mark task as in progress
        $this->update([
            'has_ongoing_visit' => true,
            'status' => 'en cours'
        ]);

        return $event;
    }

    /**
     * Finish current visit
     */
    public function finishVisit($userId = null)
    {
        // Create the event
        $event = $this->events()->create([
            'event_type' => 'finish_visit',
            'event_time' => now(),
            'user_id' => $userId
        ]);

        // Refresh events relationship to ensure we have the latest data
        $this->unsetRelation('events');
        $this->load('events');

        // Check if any users still have active visits (after creating this finish event)
        $hasAnyActiveVisits = !empty($this->getUsersWithActiveVisits());

        // Only set has_ongoing_visit to false if no users have active visits
        // Update status based on hierarchy (working > route > paused > waiting)
        $this->update([
            'has_ongoing_visit' => $hasAnyActiveVisits ? true : false,
            'status' => $this->computeTaskStatus()
        ]);

        return $event;
    }

    /**
     * Pause current visit
     */
    public function pauseVisit($userId = null)
    {
        // Create the event
        $event = $this->events()->create([
            'event_type' => self::EVENT_PAUSE_VISIT,
            'event_time' => now(),
            'user_id' => $userId
        ]);

        // Refresh events relationship to ensure we have the latest data
        $this->unsetRelation('events');
        $this->load('events');

        // Update task status based on new hierarchy
        // The hierarchy ensures: working > route > paused > waiting
        // So if someone is working, status stays "en cours" even if someone else pauses
        $this->update([
            'status' => $this->computeTaskStatus()
        ]);

        return $event;
    }

    /**
     * Resume paused visit
     */
    public function resumeVisit($userId = null)
    {
        // Create the event
        $event = $this->events()->create([
            'event_type' => self::EVENT_RESUME_VISIT,
            'event_time' => now(),
            'user_id' => $userId
        ]);

        // Refresh events relationship to ensure we have the latest data
        $this->unsetRelation('events');
        $this->load('events');

        // Update task status based on new hierarchy
        // Resuming a visit means user is now working, so status should be "en cours"
        $this->update([
            'has_ongoing_visit' => true,
            'status' => $this->computeTaskStatus()
        ]);

        return $event;
    }

    /**
     * Finish the entire task
     */
    public function finishTask($userId = null)
    {
        // Double-check: Ensure no users have active visits
        $usersWithActiveVisits = $this->getUsersWithActiveVisits();
        if (!empty($usersWithActiveVisits)) {
            throw new \Exception('Cannot finish task: Users with IDs ' . implode(', ', $usersWithActiveVisits) . ' still have active visits');
        }

        return \DB::transaction(function() use ($userId) {
            // Create the event
            $event = $this->events()->create([
                'event_type' => 'finish_task',
                'event_time' => now(),
                'user_id' => $userId
            ]);

            // Update task status
            $this->update([
                'has_ongoing_visit' => false,
                'status' => 'terminée'
            ]);

            return $event;
        });
    }

    /**
     * Cancel the task with a reason
     */
    public function cancelTask(string $reason, $userId = null)
    {
        // Create the cancel event
        $event = $this->events()->create([
            'event_type' => 'cancel_task',
            'event_time' => now(),
            'user_id' => $userId
        ]);

        // Update task status and cancellation details
        $this->update([
            'status' => 'annulée',
            'canceled_at' => now(),
            'cancellation_reason' => $reason,
            'has_ongoing_visit' => false,
        ]);

        return $event;
    }

    /**
     * User starts route (on their way to task)
     */
    public function startRoute($userId = null)
    {
        // Create the event
        $event = $this->events()->create([
            'event_type' => self::EVENT_START_ROUTE,
            'event_time' => now(),
            'user_id' => $userId
        ]);

        // Refresh events relationship to ensure we have the latest data
        $this->unsetRelation('events');
        $this->load('events');

        // Update task status based on new hierarchy
        // The hierarchy ensures: working > route > paused > waiting
        // So if someone is working, status stays "en cours" even if someone else is en route
        $this->update([
            'status' => $this->computeTaskStatus()
        ]);

        return $event;
    }

    /**
     * User ends route (cancels going to task)
     */
    public function endRoute($userId = null)
    {
        // Create the event
        $event = $this->events()->create([
            'event_type' => self::EVENT_END_ROUTE,
            'event_time' => now(),
            'user_id' => $userId
        ]);

        // Refresh events relationship to ensure we have the latest data
        $this->unsetRelation('events');
        $this->load('events');

        // Update task status based on new hierarchy
        // The hierarchy ensures: working > route > paused > waiting
        // Ending route should check if others are working or paused
        $this->update([
            'status' => $this->computeTaskStatus()
        ]);

        return $event;
    }

    /**
     * User starts working on task
     */
    public function startWork($userId = null)
    {
        // Create the event
        $event = $this->events()->create([
            'event_type' => self::EVENT_START_WORK,
            'event_time' => now(),
            'user_id' => $userId
        ]);

        // Update task status based on new hierarchy
        $this->update([
            'status' => $this->computeTaskStatus(),
            'has_ongoing_visit' => true
        ]);

        return $event;
    }

    /**
     * Check if a specific user has an active visit
     */
    public function hasUserActiveVisit($userId)
    {
        // Get all events for this user to debug
        $allEvents = $this->events()
            ->where('user_id', $userId)
            ->reorder('event_time', 'desc')
            ->orderBy('id', 'desc')
            ->get();
            
        $lastEvent = $this->events()
            ->where('user_id', $userId)
            ->whereIn('event_type', ['start_visit', 'finish_visit'])
            ->reorder('event_time', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        $hasActive = $lastEvent && $lastEvent->event_type === 'start_visit';
        
        return $hasActive;
    }

    /**
     * Check if any user other than the current one has an active visit
     */
    public function hasAnyOtherUserActiveVisit($currentUserId)
    {
        // Get all users involved (main tech + helpers)
        $allUserIds = array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        );
        
        // Remove current user
        $otherUserIds = array_diff($allUserIds, [$currentUserId]);
        
        // Check if any other user has active visit
        foreach ($otherUserIds as $userId) {
            if ($this->hasUserActiveVisit($userId)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get list of users who currently have active visits
     */
    public function getUsersWithActiveVisits()
    {
        // Get all users involved (main tech + helpers)
        $allUserIds = array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        );
        
        $usersWithActiveVisits = [];
        
        foreach ($allUserIds as $userId) {
            if ($this->hasUserActiveVisit($userId)) {
                $usersWithActiveVisits[] = $userId;
            }
        }
        
        return $usersWithActiveVisits;
    }

    /**
     * Get list of users with their names who currently have active visits
     */
    public function getUsersWithActiveVisitsDetails()
    {
        $userIds = $this->getUsersWithActiveVisits();
        
        if (empty($userIds)) {
            return [];
        }
        
        return User::whereIn('id', $userIds)
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name
                ];
            })
            ->toArray();
    }

    /**
     * Get list of users who are currently en route
     */
    public function getUsersEnRoute()
    {
        // Get all users involved (main tech + helpers)
        $allUserIds = array_unique(array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        ));
        
        $usersEnRoute = [];
        
        foreach ($allUserIds as $userId) {
            if ($this->getUserCurrentState($userId) === 'route') {
                $usersEnRoute[] = $userId;
            }
        }
        
        return $usersEnRoute;
    }

    /**
     * Get list of users with their names who are currently en route
     */
    public function getUsersEnRouteDetails()
    {
        $userIds = $this->getUsersEnRoute();
        
        if (empty($userIds)) {
            return [];
        }
        
        return User::whereIn('id', $userIds)
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name
                ];
            })
            ->toArray();
    }

    /**
     * Get list of users who are currently en pause
     */
    public function getUsersEnPause()
    {
        // Get all users involved (main tech + helpers)
        $allUserIds = array_unique(array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        ));
        
        $usersEnPause = [];
        
        foreach ($allUserIds as $userId) {
            if ($this->getUserCurrentState($userId) === 'paused') {
                $usersEnPause[] = $userId;
            }
        }
        
        return $usersEnPause;
    }

    /**
     * Get list of users with their names who are currently en pause
     */
    public function getUsersEnPauseDetails()
    {
        $userIds = $this->getUsersEnPause();
        
        if (empty($userIds)) {
            return [];
        }
        
        return User::whereIn('id', $userIds)
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name
                ];
            })
            ->toArray();
    }

    /**
     * Get total price of all services for this task
     */
    public function getTotalServicesPrice()
    {
        return $this->services()->sum('task_services.price');
    }

    /**
     * Get total task cost (products + services)
     */
    public function getTotalTaskCost()
    {
        $productsTotal = $this->taskProducts()->sum('price');
        $servicesTotal = $this->getTotalServicesPrice();
        
        return $productsTotal + $servicesTotal;
    }

    /**
     * Calculate hours worked per user from visit events.
     * Counts only time between start_visit and finish_visit, excluding time spent in pause
     * (i.e. from pause_visit to resume_visit is not counted).
     */
    public function calculateHoursWorkedPerUser()
    {
        $hoursPerUser = [];
        
        // Get all users involved in the task
        $allUserIds = array_unique(array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        ));
        
        foreach ($allUserIds as $userId) {
            // Get all visit events for this user (start, finish, pause, resume), ordered by time
            $visitEvents = $this->events()
                ->where('user_id', $userId)
                ->whereIn('event_type', ['start_visit', 'finish_visit', 'pause_visit', 'resume_visit'])
                ->orderBy('event_time', 'asc')
                ->get();
            
            $totalMinutes = 0;
            $currentStart = null; // when non-null, we are in "working" period (not paused)
            
            foreach ($visitEvents as $event) {
                if ($event->event_type === 'start_visit') {
                    $currentStart = $event->event_time;
                } elseif ($event->event_type === 'pause_visit' && $currentStart) {
                    $totalMinutes += $currentStart->diffInMinutes($event->event_time);
                    $currentStart = null;
                } elseif ($event->event_type === 'resume_visit') {
                    $currentStart = $event->event_time;
                } elseif ($event->event_type === 'finish_visit' && $currentStart) {
                    $totalMinutes += $currentStart->diffInMinutes($event->event_time);
                    $currentStart = null;
                }
            }
            
            // If there's an unfinished visit (start/resume without pause or finish), add time until now
            if ($currentStart) {
                $totalMinutes += $currentStart->diffInMinutes(now());
            }
            
            // Convert minutes to hours
            $hours = round($totalMinutes / 60, 2);
            
            if ($hours > 0) {
                $hoursPerUser[$userId] = $hours;
            }
        }
        
        return $hoursPerUser;
    }

    /**
     * Check data integrity - ensure task status matches events
     */
    public function checkDataIntegrity()
    {
        $issues = [];

        // Check if task is marked as finished but has no finish_task event
        if ($this->status === 'terminée') {
            $hasFinishEvent = $this->events()->where('event_type', 'finish_task')->exists();
            if (!$hasFinishEvent) {
                $issues[] = 'Task marked as finished but no finish_task event exists';
            }
        }

        // Check if task is marked as finished but has active visits
        if ($this->status === 'terminée') {
            $activeVisits = $this->getUsersWithActiveVisits();
            if (!empty($activeVisits)) {
                $issues[] = 'Task marked as finished but users ' . implode(', ', $activeVisits) . ' have active visits';
            }
        }

        // Check if has_ongoing_visit flag matches actual visit state
        $actualActiveVisits = !empty($this->getUsersWithActiveVisits());
        if ($this->has_ongoing_visit !== $actualActiveVisits) {
            $issues[] = 'has_ongoing_visit flag (' . ($this->has_ongoing_visit ? 'true' : 'false') . ') does not match actual visit state (' . ($actualActiveVisits ? 'true' : 'false') . ')';
        }

        return $issues;
    }

    /**
     * Fix data integrity issues
     */
    public function fixDataIntegrity()
    {
        $issues = $this->checkDataIntegrity();
        $fixed = [];

        foreach ($issues as $issue) {
            if (strpos($issue, 'Task marked as finished but users') !== false) {
                // Fix: Reset task to en cours if users have active visits
                $this->update([
                    'status' => 'en cours',
                    'has_ongoing_visit' => true
                ]);
                $fixed[] = 'Reset task status to en cours due to active visits';
            } elseif (strpos($issue, 'has_ongoing_visit flag') !== false) {
                // Fix: Update has_ongoing_visit flag to match reality
                $actualActiveVisits = !empty($this->getUsersWithActiveVisits());
                $this->update(['has_ongoing_visit' => $actualActiveVisits]);
                $fixed[] = 'Updated has_ongoing_visit flag to match actual state';
            }
        }

        return $fixed;
    }

    /**
     * Check if a specific user is currently en route
     */
    public function isUserEnRoute($userId)
    {
        $userState = $this->getUserCurrentState($userId);
        return $userState === 'route';
    }

    /**
     * Get a specific user's last event for this task (by id = most recent insert)
     */
    public function getUserLastEvent($userId)
    {
        return TaskEvent::where('task_id', $this->id)
            ->where('user_id', $userId)
            ->orderBy('id', 'desc')
            ->first();
    }

    /**
     * Get a specific user's last event for this task (alternative method)
     */
    public function getUserLastEventByTask($userId, $taskId)
    {
        return TaskEvent::where('user_id', $userId)
            ->where('task_id', $taskId)
            ->where('user_id', $userId)
            ->orderBy('id', 'desc') // Order by ID first (biggest ID = most recent event)
            ->first();
    }

    /**
     * Check if any other user (not current user) is currently working
     */
    public function hasAnyOtherUserWorking($currentUserId)
    {
        // Get all users involved (main tech + helpers)
        $allUserIds = array_unique(array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        ));

        // Check if any OTHER user is working
        foreach ($allUserIds as $userId) {
            if ($userId != $currentUserId) {
                $userState = $this->getUserCurrentState($userId);
                if ($userState === 'working') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if any other user (not current user) is currently working or en route
     */
    public function hasAnyOtherUserWorkingOrEnRoute($currentUserId)
    {
        // Get all users involved (main tech + helpers)
        $allUserIds = array_unique(array_merge(
            [$this->technician_id],
            $this->helping_user_ids ?? []
        ));

        // Check if any OTHER user is working or en route
        foreach ($allUserIds as $userId) {
            if ($userId != $currentUserId) {
                $userState = $this->getUserCurrentState($userId);
                if ($userState === 'working' || $userState === 'route') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if user has any active state (working, route, or paused) in any other task
     * Returns array with active state info or null if no active state
     */
    public static function userHasActiveStateInOtherTasks($userId, $excludeTaskId = null)
    {
        $excludeId = $excludeTaskId !== null ? (int) $excludeTaskId : null;
        $tasks = self::where('id', '!=', $excludeId)
            ->where(function($query) use ($userId) {
                $query->where('technician_id', $userId)
                      ->orWhereJsonContains('helping_user_ids', $userId);
            })
            ->get();

        foreach ($tasks as $task) {
            $lastEvent = $task->getUserLastEvent($userId);
            
            // Log for debugging
            \Log::info('userHasActiveStateInOtherTasks: Checking task', [
                'user_id' => $userId,
                'task_id' => $task->id,
                'task_name' => $task->task_name,
                'last_event' => $lastEvent ? [
                    'id' => $lastEvent->id,
                    'event_type' => $lastEvent->event_type,
                    'event_time' => $lastEvent->event_time,
                ] : null
            ]);
            
            // Only check if last event exists and is one of the *blocking* active event types.
            // Blocking: start_route, start_visit, resume_visit (user cannot start another task while en route or in visit).
            // NOT blocking: pause_visit — user may start route, start visit, or resume visit on another task while one is paused.
            if ($lastEvent) {
                $eventType = trim((string) ($lastEvent->event_type ?? ''));
                if ($eventType === 'pause_visit') {
                    continue; // Skip — paused task does not block starting/resuming another task
                }
                $isActiveEvent = in_array($eventType, [
                    'start_route',
                    'start_visit',
                    'resume_visit',
                ]);
                
                \Log::info('userHasActiveStateInOtherTasks: Event check result', [
                    'task_id' => $task->id,
                    'event_type' => $eventType,
                    'is_active_event' => $isActiveEvent
                ]);
                
                if ($isActiveEvent) {
                    // Get the state for the error message
            $userState = $task->getUserCurrentState($userId);
                    
                    return [
                        'has_active_state' => true,
                        'state' => $userState,
                        'event_type' => $eventType,
                        'task_id' => $task->id,
                        'task_name' => $task->task_name,
                        'last_event_time' => $lastEvent->event_time
                    ];
                }
            }
        }

        return null;
    }
}
