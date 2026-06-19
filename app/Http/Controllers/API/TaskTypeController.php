<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\TaskType;
use Illuminate\Http\JsonResponse;

class TaskTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $taskTypes = TaskType::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (TaskType $type) => ['id' => $type->id, 'name' => $type->name])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'task_types' => $taskTypes,
        ]);
    }
}
