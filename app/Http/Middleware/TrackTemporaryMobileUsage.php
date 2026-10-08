<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class TrackTemporaryMobileUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            try {
                $now = now();
                $query = DB::table('temporary_mobile_usage')->where('user_id', $user->id);
                $lastSeen = (clone $query)->value('last_seen_at');

                if ($lastSeen === null) {
                    DB::table('temporary_mobile_usage')->insertOrIgnore([
                        'user_id' => $user->id,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                    ]);
                } else {
                    $query->where('last_seen_at', '<=', $now->copy()->subMinutes(5))
                        ->update(['last_seen_at' => $now]);
                }
            } catch (QueryException $exception) {
                // Optional temporary telemetry must not interrupt the API, even before table setup.
            }
        }

        return $next($request);
    }
}
