<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\MobileTaskMapper;
use App\Services\TaskAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TasksRangeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
        ]);

        $start = Carbon::parse($request->query('start'))->startOfDay();
        $end = Carbon::parse($request->query('end'))->endOfDay();

        $tasksQuery = Task::with([
            'client.image',
            'client.city',
            'taskProducts.product',
            'services',
            'technician.image',
            'adminDeliveryReceivedByUser',
        ]);
        if (! TaskAccess::canViewAll($request->user())) {
            $tasksQuery->where(function ($query) {
                $query->where('technician_id', Auth::id())
                    ->orWhereJsonContains('helping_user_ids', Auth::id());
            });
        }

        $tasks = $tasksQuery
            ->whereNull('deployment_id')
            ->whereBetween('task_date', [$start, $end])
            ->orderBy('task_date', 'asc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Task $task) => MobileTaskMapper::mapTask($task))
            ->values();

        return response()->json([
            'success' => true,
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'tasks' => $tasks,
        ]);
    }
}
