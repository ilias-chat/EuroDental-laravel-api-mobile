<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

class TaskAccess
{
    public static function canViewAll(User $user): bool
    {
        return in_array('tasks_view_all', $user->getPermissions(), true);
    }

    public static function canWrite(User $user): bool
    {
        return in_array('tasks_write', $user->getPermissions(), true);
    }

    public static function canView(User $user, Task $task): bool
    {
        return self::canViewAll($user)
            || (int) $task->technician_id === (int) $user->id
            || in_array((int) $user->id, array_map('intval', $task->helping_user_ids ?? []), true);
    }

    public static function canEdit(User $user, Task $task): bool
    {
        return self::canWrite($user)
            && (self::canViewAll($user) || (int) $task->technician_id === (int) $user->id);
    }
}
