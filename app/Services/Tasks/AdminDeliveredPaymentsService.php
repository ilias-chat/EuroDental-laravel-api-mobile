<?php

namespace App\Services\Tasks;

use App\Models\Task;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Paiements remis à l'administration — même logique que l’index tâches Laravel (modal Alpine).
 */
class AdminDeliveredPaymentsService
{
    /**
     * @return array{list: Collection<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function paginatedList(int $page, int $perPage): array
    {
        $deliveryTasks = Task::with([
            'technician',
            'adminDeliveryReceivedByUser',
            'sourceTaskForDelivery.client',
            'sourceTaskForDelivery.events',
            'events',
        ])
            ->whereNotNull('admin_delivery_received_by_user_id')
            ->where('status', 'terminée')
            ->where('task_name', 'like', "Remise paiement à l'administration%")
            ->get();

        $list = $deliveryTasks->map(function (Task $delivery) {
            $source = $delivery->sourceTaskForDelivery;
            $clientName = $source && $source->client
                ? $source->client->first_name.' '.$source->client->last_name
                : null;
            $amount = $source && $source->admin_delivery_amount !== null
                ? (float) $source->admin_delivery_amount
                : null;
            $finishEvent = $delivery->events->firstWhere('event_type', 'finish_task');
            $dateReceived = $finishEvent && $finishEvent->event_time
                ? Carbon::parse($finishEvent->event_time)->format('Y-m-d')
                : ($delivery->finished_at ? Carbon::parse($delivery->finished_at)->format('Y-m-d') : null);
            $dateCollectedFromClient = null;
            if ($source) {
                $sourceEvents = $source->events ?? collect();
                $sourceFinishEvent = $sourceEvents->firstWhere('event_type', 'finish_task');
                $dateCollectedFromClient = $sourceFinishEvent && $sourceFinishEvent->event_time
                    ? Carbon::parse($sourceFinishEvent->event_time)->format('Y-m-d')
                    : ($source->finished_at ? Carbon::parse($source->finished_at)->format('Y-m-d') : null);
            }

            return [
                'id' => $delivery->id,
                'delivered_by' => $delivery->technician
                    ? $delivery->technician->first_name.' '.$delivery->technician->last_name
                    : null,
                'received_by' => $delivery->adminDeliveryReceivedByUser
                    ? $delivery->adminDeliveryReceivedByUser->first_name.' '.$delivery->adminDeliveryReceivedByUser->last_name
                    : null,
                'client_name' => $clientName,
                'amount' => $amount,
                'date_received' => $dateReceived,
                'date_collected_from_client' => $dateCollectedFromClient,
            ];
        })->values()->sortByDesc(function ($item) {
            return $item['date_received'] ?? '';
        })->values();

        $total = $list->count();
        $lastPage = (int) ceil($total / $perPage) ?: 1;
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;
        $paginatedList = $list->slice($offset, $perPage)->values();

        return [
            'list' => $paginatedList,
            'pagination' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }
}
