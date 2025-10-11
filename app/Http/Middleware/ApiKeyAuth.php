<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\ApiKey;

class ApiKeyAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @param  string|null  $permission  Required permission for this endpoint
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next, ?string $permission = null)
    {
        \Log::info('🔑 ApiKeyAuth middleware called', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'has_header' => $request->hasHeader('X-API-Key'),
            'has_param' => $request->has('api_key'),
            'content_type' => $request->header('Content-Type'),
            'permission' => $permission
        ]);

        $apiKey = $this->extractApiKey($request);

        if (!$apiKey) {
            \Log::warning('🔑 API key not found in request');
            return response()->json([
                'success' => false,
                'message' => 'API key required. Provide via X-API-Key header or api_key parameter.'
            ], 401);
        }

        $keyModel = ApiKey::verify($apiKey);

        if (!$keyModel) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired API key.'
            ], 401);
        }

        // Check permission if specified
        if ($permission && !$keyModel->hasPermission($permission)) {
            return response()->json([
                'success' => false,
                'message' => "Insufficient permissions. Required: {$permission}"
            ], 403);
        }

        // Add the API key model to the request for potential use in controllers
        $request->attributes->set('api_key', $keyModel);

        return $next($request);
    }

    /**
     * Extract API key from request headers or parameters.
     *
     * @param Request $request
     * @return string|null
     */
    private function extractApiKey(Request $request): ?string
    {
        // Check X-API-Key header first (recommended)
        $apiKey = $request->header('X-API-Key');

        if (!$apiKey) {
            // Fallback to api_key parameter (for Google Sheets compatibility)
            $apiKey = $request->input('api_key');
        }

        return $apiKey;
    }
}