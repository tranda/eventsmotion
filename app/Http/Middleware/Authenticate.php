<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // For API requests (which expect JSON), return null to trigger 401 response
        // For web requests, we could redirect to login but we don't have that route
        if ($request->expectsJson()) {
            return null;
        }

        // For non-API requests, return null since we don't have a login route
        return null;
    }
}
