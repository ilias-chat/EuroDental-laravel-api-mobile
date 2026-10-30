<?php

namespace App\Services\Tasks;

use App\Models\Task;
use App\Models\User;

class TaskCommentAccess
{
    public function canView(User $user, Task $task): bool
    {
        return $this->participant($user, $task) || $this->permissions($user)->contains('tasks_view_all');
    }

    public function canPost(User $user, Task $task): bool
    {
        $permissions = $this->permissions($user);
        return $this->participant($user, $task)
            || ($permissions->contains('tasks_view_all') && $permissions->contains('tasks_write'));
    }

    public function canFollow(User $user): bool
    {
        $permissions = $this->permissions($user);
        return $permissions->contains('tasks_view_all') && $permissions->contains('tasks_write');
    }

    public function participant(User $user, Task $task): bool
    {
        return (int) $task->technician_id === (int) $user->id
            || in_array((int) $user->id, array_map('intval', $task->helping_user_ids ?? []), true);
    }

    private function permissions(User $user)
    {
        $user->loadMissing('profile.permissions');
        return $user->profile?->permissions->pluck('code') ?? collect();
    }
}
