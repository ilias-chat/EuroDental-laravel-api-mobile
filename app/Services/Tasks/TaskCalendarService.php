<?php

namespace App\Services\Tasks;

use App\Models\Client;
use App\Models\Deployment;
use App\Models\Task;
use App\Models\TaskType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TaskCalendarService
{
    public function calendarPayload(Carbon $start, Carbon $end, User $user): array
    {
        $deploymentsQuery = Deployment::with([
            'responsible.image',
            'driver.image',
            'city',
            'tasks.technician.image',
            'tasks.client.image',
            'tasks.client.city',
            'tasks.services',
        ])->whereBetween('deployment_date', [$start, $end]);

        $deployments = $deploymentsQuery->get();

        $tasksQuery = Task::with(['technician.image', 'client.image', 'client.city', 'services'])
            ->whereBetween('task_date', [$start, $end])
            ->whereNull('deployment_id');

        if (! $this->userCanViewAllTasks($user)) {
            $tasksQuery->where('technician_id', $user->id);
            $deployments = $deployments->filter(function ($deployment) use ($user) {
                $userId = (int) $user->id;
                $teamIds = array_map('intval', (array) ($deployment->team_member_ids ?? []));
                $hosterIds = array_map('intval', (array) ($deployment->hosters ?? []));

                return (int) $deployment->responsible_id === $userId
                    || (int) $deployment->driver_id === $userId
                    || in_array($userId, $teamIds, true)
                    || in_array($userId, $hosterIds, true)
                    || $deployment->tasks->contains('technician_id', $userId);
            });
        }

        $tasks = $tasksQuery->get();

        $groupedDeployments = $deployments->groupBy(function ($deployment) {
            return Carbon::parse($deployment->deployment_date)->startOfDay()->format('Y-m-d');
        });

        $groupedTasks = $tasks->groupBy(function ($task) {
            return Carbon::parse($task->task_date)->startOfDay()->format('Y-m-d');
        });

        $result = [
            'deployments' => [],
            'tasks' => [],
        ];

        $viewerId = (int) $user->id;

        foreach ($groupedDeployments as $date => $deploymentsForDate) {
            $result['deployments'][$date] = $deploymentsForDate->map(function ($deployment) use ($viewerId) {
                $teamMembers = $deployment->teamMembers();
                $hosters = $deployment->getHosters();

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
                    'team_member_ids' => $deployment->team_member_ids,
                    'team_members' => $teamMembers->map(function ($member) {
                        return [
                            'id' => $member->id,
                            'name' => $member->first_name.' '.$member->last_name,
                            'image' => $member->image ? asset('storage/'.$member->image->image_name) : null,
                        ];
                    }),
                    'hosters' => $deployment->hosters ?? [],
                    'hosters_detail' => $hosters->map(function ($member) {
                        return [
                            'id' => $member->id,
                            'name' => $member->first_name.' '.$member->last_name,
                            'image' => $member->image ? asset('storage/'.$member->image->image_name) : null,
                        ];
                    })->values()->all(),
                    'tasks' => $deployment->tasks->map(function ($task) use ($viewerId) {
                        return $this->serializeTask($task, $viewerId);
                    }),
                    'tasks_count' => $deployment->tasks->count(),
                ];
            });
        }

        foreach ($groupedTasks as $date => $tasksForDate) {
            $result['tasks'][$date] = $tasksForDate->map(function ($task) use ($viewerId) {
                return $this->serializeStandaloneTask($task, $viewerId);
            });
        }

        $result['meta'] = $this->buildCalendarFilterMeta($user, $deployments, $tasks);

        return $result;
    }

    /**
     * Lists for filter UI: full user/client lists for admins; scoped lists for technicians.
     * Task types always come from the task_types table (values must match tasks.task_type strings).
     */
    private function buildCalendarFilterMeta(User $user, Collection $deployments, Collection $tasks): array
    {
        $canViewAll = $this->userCanViewAllTasks($user);

        if ($canViewAll) {
            $technicians = User::with('image')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(function (User $u) {
                    return [
                        'id' => $u->id,
                        'name' => trim($u->first_name.' '.$u->last_name),
                        'image' => $u->image && $u->image->image_name
                            ? asset('storage/'.$u->image->image_name)
                            : null,
                    ];
                })
                ->values()
                ->all();

            $clients = Client::admin()
                ->with('image')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(function (Client $c) {
                    return [
                        'id' => $c->id,
                        'name' => trim($c->first_name.' '.$c->last_name),
                        'image' => $c->image ? asset('storage/'.$c->image->image_name) : null,
                    ];
                })
                ->values()
                ->all();
        } else {
            $techIds = collect();
            foreach ($tasks as $task) {
                if ($task->technician_id) {
                    $techIds->push((int) $task->technician_id);
                }
            }
            foreach ($deployments as $deployment) {
                foreach ($deployment->tasks as $task) {
                    if ($task->technician_id) {
                        $techIds->push((int) $task->technician_id);
                    }
                }
            }
            $techIds = $techIds->unique()->values();
            $uid = (int) $user->id;
            if (! $techIds->contains($uid)) {
                $techIds->push($uid);
            }

            $technicians = User::with('image')
                ->whereIn('id', $techIds)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get()
                ->map(function (User $u) {
                    return [
                        'id' => $u->id,
                        'name' => trim($u->first_name.' '.$u->last_name),
                        'image' => $u->image && $u->image->image_name
                            ? asset('storage/'.$u->image->image_name)
                            : null,
                    ];
                })
                ->values()
                ->all();

            $clientIds = collect();
            foreach ($tasks as $task) {
                if ($task->client_id) {
                    $clientIds->push((int) $task->client_id);
                }
            }
            foreach ($deployments as $deployment) {
                foreach ($deployment->tasks as $task) {
                    if ($task->client_id) {
                        $clientIds->push((int) $task->client_id);
                    }
                }
            }
            $clientIds = $clientIds->unique()->values();

            $clients = $clientIds->isEmpty()
                ? []
                : Client::admin()
                    ->with('image')
                    ->whereIn('id', $clientIds)
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->get()
                    ->map(function (Client $c) {
                        return [
                            'id' => $c->id,
                            'name' => trim($c->first_name.' '.$c->last_name),
                            'image' => $c->image ? asset('storage/'.$c->image->image_name) : null,
                        ];
                    })
                    ->values()
                    ->all();
        }

        $taskTypes = TaskType::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (TaskType $tt) => [
                'id' => $tt->id,
                'name' => $tt->name,
            ])
            ->values()
            ->all();

        return [
            'technicians' => $technicians,
            'clients' => $clients,
            'task_types' => $taskTypes,
        ];
    }

    private function userCanViewAllTasks(User $user): bool
    {
        return $user->profile
            && $user->profile->permissions->pluck('code')->contains('tasks_view_all');
    }

    private function serializeTask(Task $task, int $viewerUserId): array
    {
        return [
            'id' => $task->id,
            'task_name' => $task->task_name,
            'task_type' => $task->task_type,
            'description' => $task->description,
            'status' => $task->status,
            'urgent' => $task->urgent,
            'is_paid' => $task->is_paid,
            'hourly_rate' => $task->hourly_rate,
            'amount_paid' => $task->amount_paid,
            'admin_delivery_amount' => $task->admin_delivery_amount,
            'admin_delivery_task_id' => $task->admin_delivery_task_id,
            'task_date' => $task->task_date ? $task->task_date->format('Y-m-d') : null,
            'has_ongoing_visit' => (bool) ($task->has_ongoing_visit ?: false),
            'deployment_id' => $task->deployment_id,
            'technician_id' => $task->technician_id,
            'technician_name' => $task->technician ? ($task->technician->first_name.' '.$task->technician->last_name) : null,
            'technician_image' => $task->technician && $task->technician->image ? asset('storage/'.$task->technician->image->image_name) : null,
            'client_id' => $task->client_id,
            'client_name' => $task->client ? ($task->client->first_name.' '.$task->client->last_name) : null,
            'client_image' => $task->client && $task->client->image ? asset('storage/'.$task->client->image->image_name) : null,
            'client_city' => $task->client && $task->client->city ? $task->client->city->name : null,
            'helping_user_ids' => $task->helping_user_ids,
            'is_main_technician' => $task->technician_id == $viewerUserId,
        ];
    }

    private function serializeStandaloneTask(Task $task, int $viewerUserId): array
    {
        $row = $this->serializeTask($task, $viewerUserId);
        $row['deployment_id'] = null;

        return $row;
    }
}
