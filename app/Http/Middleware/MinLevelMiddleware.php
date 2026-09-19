<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Gate a route by a minimum access_level, e.g. `minlevel:2` for Event
 * Managers and up. Complements AdminMiddleware (fixed at >= 3) for routes
 * that should open to a lower role — the schedule builder, for instance.
 */
class MinLevelMiddleware
{
    public function handle(Request $request, Closure $next, $level)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please log in.'
            ], 401);
        }

        if (($request->user()->access_level ?? 0) < (int) $level) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Insufficient access level.'
            ], 403);
        }

        return $next($request);
    }
}
