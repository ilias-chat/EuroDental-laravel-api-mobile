<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskFollower;
use App\Services\Tasks\TaskCommentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskFollowController extends Controller
{
    public function show(Request $request, Task $task, TaskCommentAccess $access)
    {
        abort_unless($access->canView($request->user(), $task), 403);
        return response()->json($this->state($request, $task, $access));
    }

    public function store(Request $request, Task $task, TaskCommentAccess $access)
    {
        return DB::transaction(function () use ($request, $task, $access) {
            $task = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($access->canFollow($request->user()), 403, 'Le suivi est reserve aux responsables autorises.');
            TaskFollower::firstOrCreate(['task_id' => $task->id, 'user_id' => $request->user()->id]);
            return response()->json($this->state($request, $task, $access) + ['message' => 'Vous suivez cette tache.']);
        });
    }

    public function destroy(Request $request, Task $task, TaskCommentAccess $access)
    {
        return DB::transaction(function () use ($request, $task, $access) {
            $task = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless($access->canView($request->user(), $task), 403);
            TaskFollower::where('task_id', $task->id)->where('user_id', $request->user()->id)->delete();
            return response()->json($this->state($request, $task, $access) + ['message' => 'Vous ne suivez plus cette tache.']);
        });
    }

    private function state(Request $request, Task $task, TaskCommentAccess $access): array
    {
        return [
            'following' => TaskFollower::where('task_id', $task->id)->where('user_id', $request->user()->id)->exists(),
            'can_follow' => $access->canFollow($request->user()),
            'participant' => $access->participant($request->user(), $task),
        ];
    }
}
