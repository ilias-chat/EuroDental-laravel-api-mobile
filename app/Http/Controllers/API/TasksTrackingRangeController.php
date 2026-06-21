<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\MobileTaskMapper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TasksTrackingRangeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $start = Carbon::parse($request->query('start'))->startOfDay();
        $end = Carbon::parse($request->query('end'))->endOfDay();
        $userId = $request->filled('user_id') ? (int) $request->query('user_id') : null;

        $query = Task::with([
            'client.image',
            'client.city',
            'taskProducts.product',
            'services',
            'technician.image',
        ])->whereBetween('task_date', [$start, $end]);

        if ($userId !== null) {
            $query->where(function ($q) use ($userId) {
                $q->where('technician_id', $userId)
                    ->orWhereJsonContains('helping_user_ids', $userId);
            });
        }

        $tasks = $query
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
