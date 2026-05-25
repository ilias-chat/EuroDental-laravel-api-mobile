<?php

namespace App\Services;

use App\Models\Client;
use Carbon\Carbon;

class WarrantyService
{
    /**
     * Collect active warranty products for the given client.
     *
     * @param Client|null $client
     * @param int|null    $limit
     * @return array
     */
    public static function getActiveWarrantyProducts(?Client $client, ?int $limit = null): array
    {
        if (!$client) {
            return [];
        }

        $orders = $client->orders()
            ->whereNotIn('status', ['pending', 'canceled'])
            ->with(['deliveryNotes.deliveryNoteItems.orderItem.product'])
            ->get();

        $products = $orders->flatMap(function ($order) {
            return $order->deliveryNotes->flatMap(function ($deliveryNote) {
                return $deliveryNote->deliveryNoteItems
                    ->filter(function ($item) {
                        $product = $item->orderItem->product ?? null;

                        return $product
                            && $product->has_warranty
                            && $product->warranty_duration_months > 0;
                    })
                    ->map(function ($item) use ($deliveryNote) {
                        $product = $item->orderItem->product;
                        $order = $item->orderItem->order ?? null;
                        $deliveryDate = $deliveryNote->delivery_date ?? $deliveryNote->created_at;

                        $warrantyStart = Carbon::parse($deliveryDate);
                        $warrantyEnd = $warrantyStart->copy()->addMonths($product->warranty_duration_months);
                        $daysLeft = now()->diffInDays($warrantyEnd, false);

                        if ($daysLeft < 0) {
                            return null;
                        }

                        return [
                            'delivery_note_item_id' => $item->id,
                            'product_id' => $product->id,
                            'product_name' => $product->product_name,
                            'order_id' => $order ? $order->id : null,
                            'order_number' => $order ? $order->order_number : null,
                            'purchase_date' => $warrantyStart->toDateString(),
                            'warranty_end' => $warrantyEnd->toDateString(),
                            'delivered_quantity' => $item->delivered_quantity,
                            'days_left' => (int) $daysLeft,
                        ];
                    })
                    ->filter();
            });
        });

        $sorted = $products->sortBy('warranty_end')->values();

        if ($limit !== null) {
            $sorted = $sorted->take($limit)->values();
        }

        return $sorted->toArray();
    }
}

