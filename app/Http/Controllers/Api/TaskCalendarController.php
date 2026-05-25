<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Tasks\AdminDeliveredPaymentsService;
use App\Services\Tasks\TaskCalendarService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskCalendarController extends Controller
{
    public function __construct(
        private TaskCalendarService $taskCalendarService,
        private AdminDeliveredPaymentsService $adminDeliveredPaymentsService
    ) {}

    public function month(Request $request): JsonResponse
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
        ]);

        $start = Carbon::parse($request->query('start'));
        $end = Carbon::parse($request->query('end'));

        return response()->json(
            $this->taskCalendarService->calendarPayload($start, $end, $request->user())
        );
    }

    /**
     * Même données que GET /admin/tasks/delivered-payments (TasksController::deliveredPaymentsList).
     */
    public function deliveredPayments(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 10), 1), 100);
        $page = max((int) $request->get('page', 1), 1);

        return response()->json(
            $this->adminDeliveredPaymentsService->paginatedList($page, $perPage)
        );
    }

    /**
     * Client list for create-task modal, aligned with admin tasks index mapping.
     */
    public function createClients(): JsonResponse
    {
        $clients = Client::admin()
            ->with(['image', 'city'])
            ->get()
            ->map(function (Client $client): array {
                return [
                    'id' => $client->id,
                    'name' => trim(($client->first_name ?? '') . ' ' . ($client->last_name ?? '')),
                    'image' => $client->image ? asset('storage/' . $client->image->image_name) : null,
                    'city' => $client->city ? $client->city->name : null,
                ];
            })
            ->values();

        return response()->json(['clients' => $clients]);
    }
}
