<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class UsersListController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $users = User::with('image')
            ->where('is_blocked', false)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => trim($user->first_name . ' ' . $user->last_name),
                'image' => $user->image && $user->image->image_name
                    ? storage_public_url($user->image->image_name)
                    : null,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'users' => $users,
        ]);
    }
}
