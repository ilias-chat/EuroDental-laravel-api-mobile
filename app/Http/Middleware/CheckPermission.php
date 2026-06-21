<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();

        if (! $user || ! $user->profile || ! $user->profile->permissions->pluck('code')->contains($permission)) {
            abort(403, 'Unauthorized.');
        }

        return $next($request);
    }
}
