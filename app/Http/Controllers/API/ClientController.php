<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $query = Client::with(['image', 'city']);
    
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$search}%"]);
        }
    
        if ($request->has('sort')) {
            $sort = $request->sort;
            $direction = $request->get('direction', 'asc');
            if (in_array($sort, ['first_name', 'last_name', 'created_at'])) {
                $query->orderBy($sort, $direction);
            }
        }
    
        $clients = $query->paginate(10);
    
        $clients->getCollection()->transform(function ($client) {
            return [
                'id' => $client->id,
                'name' => $client->first_name . ' ' . $client->last_name,
                'phone' => $client->phone_number,
                'email' => $client->email,
                'ice' => $client->ice,
                'city' => $client->city ? $client->city->name : null,
                'image_url' => $client->image ? asset('storage/' . $client->image->image_name) : null
            ];
        });
    
        return response()->json([
            'clients' => $clients
        ]);
    }

    public function search(Request $request)
    {
        $q = $request->get('q');

        $clients = Client::with(['city', 'image'])
            ->when($q, function ($query) use ($q) {
                $terms = preg_split('/\s+/', trim($q));
                foreach ($terms as $term) {
                    if ($term === '') {
                        continue;
                    }
                    $query->where(function ($sub) use ($term) {
                        $sub->where('first_name', 'LIKE', "%{$term}%")
                            ->orWhere('last_name', 'LIKE', "%{$term}%");
                    });
                }
            })
            ->orderBy('first_name')
            ->limit(50)
            ->get();

        $data = $clients->map(function ($client) {
            return [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'name' => $client->first_name.' '.$client->last_name,
                'city' => optional($client->city)->name,
                'image' => ($client->image && $client->image->image_name)
                    ? asset('storage/'.$client->image->image_name)
                    : null,
            ];
        });

        return response()->json(['clients' => $data]);
    }

    /**
     * Get a specific client by ID
     * Example: GET /api/clients/123
     */
    public function show($clientId)
    {
        $client = Client::with(['city', 'image'])->find($clientId);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable'
            ], 404);
        }

        // Format the response
        $response = [
            'id' => $client->id,
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'city_name' => $client->city ? $client->city->name : null,
            'image_name' => $client->image ? asset('storage/' . $client->image->image_name) : null,
            'phone_number' => $client->phone_number,
            'email' => $client->email,
            'ice' => $client->ice,
            'address' => $client->address,
            'created_at' => $client->created_at ? $client->created_at->toISOString() : null
        ];

        return response()->json([
            'success' => true,
            'client' => $response
        ]);
    }

    /**
     * Get tasks for a specific client with pagination and filtering
     * Example: GET /api/clients/123/tasks?page=1&limit=20&status=en cours
     */
    public function tasks(Request $request, $clientId)
    {
        $client = Client::find($clientId);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable'
            ], 404);
        }

        $query = $client->tasks();

        // Filter by status if provided
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Get pagination parameters
        $page = $request->get('page', 1);
        $limit = min($request->get('limit', 20), 100); // Max 100 items per page

        $tasks = $query->orderBy('task_date', 'desc')
                      ->paginate($limit, ['*'], 'page', $page);

        // Transform tasks to match the required format
        $tasks->getCollection()->transform(function ($task) {
            return [
                'id' => $task->id,
                'task_name' => $task->task_name,
                'task_type' => $task->task_type,
                'status' => $task->status,
                'urgent' => $task->urgent,
                'task_date' => $task->task_date,
                'description' => $task->description,
                'technician_id' => $task->technician_id,
                'created_at' => $task->created_at ? $task->created_at->toISOString() : null
            ];
        });

        // Format pagination data
        $pagination = [
            'current_page' => $tasks->currentPage(),
            'total_pages' => $tasks->lastPage(),
            'total_items' => $tasks->total(),
            'has_next' => $tasks->hasMorePages(),
            'has_prev' => $tasks->currentPage() > 1
        ];

        return response()->json([
            'success' => true,
            'tasks' => $tasks->items(),
            'pagination' => $pagination
        ]);
    }

    /**
     * Get orders for a specific client with pagination and filtering
     * Example: GET /api/clients/123/orders?page=1&limit=20&status=completed
     */
    public function orders(Request $request, $clientId)
    {
        $client = Client::find($clientId);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable'
            ], 404);
        }

        $query = $client->orders();

        // Filter by status if provided
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Get pagination parameters
        $page = $request->get('page', 1);
        $limit = min($request->get('limit', 20), 100); // Max 100 items per page

        $orders = $query->with('items')
                       ->orderBy('created_at', 'desc')
                       ->paginate($limit, ['*'], 'page', $page);

        // Transform orders to match the required format
        $orders->getCollection()->transform(function ($order) {
            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_date' => $order->created_at ? $order->created_at->format('Y-m-d') : null,
                'status' => $order->status,
                'total_amount' => (float) $order->total_with_tax,
                'payment_status' => $order->isFullyPaid() ? 'paid' : 'unpaid',
                'items_count' => $order->items->count(),
                'created_at' => $order->created_at ? $order->created_at->toISOString() : null
            ];
        });

        // Format pagination data
        $pagination = [
            'current_page' => $orders->currentPage(),
            'total_pages' => $orders->lastPage(),
            'total_items' => $orders->total(),
            'has_next' => $orders->hasMorePages(),
            'has_prev' => $orders->currentPage() > 1
        ];

        return response()->json([
            'success' => true,
            'orders' => $orders->items(),
            'pagination' => $pagination
        ]);
    }

    /**
     * Get warranty products for a specific client with pagination and category filtering
     * Example: GET /api/clients/123/products?page=1&limit=20&category=Implants
     * 
     * Current Features:
     * - Shows ONLY warranty products purchased by this specific client
     * - Category filtering
     * - Pagination
     * - Real warranty calculations based on actual delivery date
     * - Client-specific filtering through delivery notes
     */
    public function products(Request $request, $clientId)
    {
        $client = Client::find($clientId);

        if (!$client) {
            return response()->json([
                'success' => false,
                'message' => 'Client introuvable'
            ], 404);
        }

        // Get orders that have been delivered (not pending/canceled)
        $orders = $client->orders()
            ->whereNotIn('status', ['pending', 'canceled'])
            ->with([
                'deliveryNotes.deliveryNoteItems.orderItem.product.category'
            ])->get();

        // Get warranty products from delivered orders
        $warrantyProducts = $orders->flatMap(function ($order) {
            return $order->deliveryNotes->flatMap(function ($deliveryNote) {
                return $deliveryNote->deliveryNoteItems->filter(function ($dni) {
                    return $dni->orderItem
                        && $dni->orderItem->product
                        && $dni->orderItem->product->has_warranty
                        && $dni->orderItem->product->warranty_duration_months > 0;
                })->map(function ($dni) use ($deliveryNote) {
                    $product = $dni->orderItem->product;
                    
                    // Ensure category is loaded
                    if (!$product->relationLoaded('category')) {
                        $product->load('category');
                    }
                    
                    $deliveryDate = $deliveryNote->delivery_date ?? $deliveryNote->created_at;

                    $warrantyStart = \Carbon\Carbon::parse($deliveryDate);
                    $warrantyEnd = $warrantyStart->copy()->addMonths($product->warranty_duration_months);
                    
                    // Ensure the warranty end date is valid
                    if (!$warrantyEnd->isValid()) {
                        $warrantyEnd = $warrantyStart->copy()->addMonths($product->warranty_duration_months)->endOfMonth();
                    }
                    
                    $daysLeft = now()->diffInDays($warrantyEnd, false);

                    return [
                        'id' => $product->id,
                        'product_name' => $product->product_name,
                        'category' => $product->category ? $product->category->category : null,
                        'category_id' => $product->id_category,
                        'purchase_date' => $warrantyStart->toDateString(),
                        'warranty_expiry' => $warrantyEnd->toDateString(),
                        'warranty_status' => $daysLeft > 0 ? 'active' : 'expired',
                        'days_left_in_warranty' => (string) max(0, $daysLeft),
                        'serial_number' => $product->reference,
                        'price' => (float) $product->price,
                        'technician_notes' => 'Product delivered successfully'
                    ];
                });
            });
        });

        // Filter by category if provided
        if ($request->has('category') && $request->category) {
            $warrantyProducts = $warrantyProducts->filter(function ($product) use ($request) {
                return $product['category'] === $request->category;
            });
        }

        // Convert to collection and paginate
        $collection = collect($warrantyProducts->values());
        $perPage = min($request->get('limit', 20), 100); // Max 100 items per page
        $currentPage = $request->get('page', 1);
        $offset = ($currentPage - 1) * $perPage;
        
        $paginatedProducts = $collection->slice($offset, $perPage);
        $total = $collection->count();
        $lastPage = ceil($total / $perPage);

        // Format pagination data
        $pagination = [
            'current_page' => $currentPage,
            'total_pages' => $lastPage,
            'total_items' => $total,
            'has_next' => $currentPage < $lastPage,
            'has_prev' => $currentPage > 1
        ];

        return response()->json([
            'success' => true,
            'products' => $paginatedProducts->values(),
            'pagination' => $pagination
        ]);
    }
}
