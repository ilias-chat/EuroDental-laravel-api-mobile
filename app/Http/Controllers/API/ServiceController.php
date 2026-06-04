<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Service;

class ServiceController extends Controller
{
    /**
     * All services for task selection modals (mobile technicians).
     */
    public function getAll()
    {
        $services = Service::with('category')
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'services' => $services,
        ]);
    }
}
