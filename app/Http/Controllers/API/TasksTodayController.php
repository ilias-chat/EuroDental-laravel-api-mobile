<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Services\MobileTaskMapper;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class TasksTodayController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = Carbon::today();

        $tasks = Task::with([
            'client.image',
            'client.city',
            'taskProducts.product',
            'services',
            'technician.image',
            'adminDeliveryReceivedByUser',
        ])
            ->where(function ($query) {
                $query->where('technician_id', Auth::id())
                    ->orWhereJsonContains('helping_user_ids', Auth::id());
            })
            ->whereNull('deployment_id')
            ->whereDate('task_date', $today)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Task $task) => MobileTaskMapper::mapTask($task))
            ->values();

        return response()->json([
            'success' => true,
            'date' => $today->format('Y-m-d'),
            'tasks' => $tasks,
        ]);
    }
}
