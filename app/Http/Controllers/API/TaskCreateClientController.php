<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskCreateClientController extends Controller
{
    /**
     * Searchable paginated client list for create-task forms (aligned with admin tasks index).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 50);
        $q = trim((string) $request->get('q', ''));

        $query = Client::admin()
            ->with(['image', 'city'])
            ->orderBy('first_name')
            ->orderBy('last_name');

        if ($q !== '') {
            $terms = preg_split('/\s+/', $q);
            foreach ($terms as $term) {
                if ($term === '') {
                    continue;
                }
                $query->where(function ($sub) use ($term) {
                    $sub->where('first_name', 'LIKE', "%{$term}%")
                        ->orWhere('last_name', 'LIKE', "%{$term}%");
                });
            }
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'clients' => $paginator->getCollection()
                ->map(fn (Client $client) => [
                    'id' => $client->id,
                    'name' => trim(($client->first_name ?? '').' '.($client->last_name ?? '')),
                    'image' => storage_public_url($client->image?->image_name),
                    'city' => $client->city?->name,
                ])
                ->values(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }
}
