<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use Illuminate\Http\Request;
use App\Models\ApiKey;
use Carbon\Carbon;

class ApiKeyController extends BaseController
{
    /**
     * Get all API keys for admin users.
     * Only shows non-sensitive information.
     */
    public function index()
    {
        try {
            $apiKeys = ApiKey::with('creator:id,name,username')
                ->select(['id', 'name', 'key_prefix', 'permissions', 'created_by', 'last_used_at', 'expires_at', 'is_active', 'created_at'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $apiKeys,
                'message' => 'API keys retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving API keys', [$e->getMessage()], 500);
        }
    }

    /**
     * Create a new API key.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'permissions' => 'required|array',
                'permissions.*' => 'string|in:races.bulk-update,races.read,races.write,*',
                'expires_in_days' => 'nullable|integer|min:1|max:365'
            ]);

            $expiresAt = null;
            if ($request->expires_in_days) {
                $expiresAt = Carbon::now()->addDays($request->expires_in_days);
            }

            $result = ApiKey::generate(
                $request->name,
                $request->permissions,
                auth()->id(),
                $expiresAt
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'api_key' => $result['api_key'], // Only show the full key once!
                    'model' => $result['model']->load('creator:id,name,username')
                ],
                'message' => 'API key created successfully. Store this key securely - it will not be shown again!'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation error', $e->errors(), 422);
        } catch (\Exception $e) {
            return $this->sendError('Error creating API key', [$e->getMessage()], 500);
        }
    }

    /**
     * Show a specific API key (without the actual key).
     */
    public function show($id)
    {
        try {
            $apiKey = ApiKey::with('creator:id,name,username')
                ->select(['id', 'name', 'key_prefix', 'permissions', 'created_by', 'last_used_at', 'expires_at', 'is_active', 'created_at'])
                ->find($id);

            if (!$apiKey) {
                return $this->sendError('API key not found', [], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $apiKey,
                'message' => 'API key retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving API key', [$e->getMessage()], 500);
        }
    }

    /**
     * Update an API key (name, permissions, expiration).
     */
    public function update(Request $request, $id)
    {
        try {
            $apiKey = ApiKey::find($id);

            if (!$apiKey) {
                return $this->sendError('API key not found', [], 404);
            }

            $request->validate([
                'name' => 'sometimes|string|max:255',
                'permissions' => 'sometimes|array',
                'permissions.*' => 'string|in:races.bulk-update,races.read,races.write,*',
                'expires_in_days' => 'nullable|integer|min:1|max:365',
                'is_active' => 'sometimes|boolean'
            ]);

            $updateData = $request->only(['name', 'permissions', 'is_active']);

            if ($request->has('expires_in_days')) {
                if ($request->expires_in_days) {
                    $updateData['expires_at'] = Carbon::now()->addDays($request->expires_in_days);
                } else {
                    $updateData['expires_at'] = null;
                }
            }

            $apiKey->update($updateData);
            $apiKey->load('creator:id,name,username');

            return response()->json([
                'success' => true,
                'data' => $apiKey,
                'message' => 'API key updated successfully'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation error', $e->errors(), 422);
        } catch (\Exception $e) {
            return $this->sendError('Error updating API key', [$e->getMessage()], 500);
        }
    }

    /**
     * Revoke (deactivate) an API key.
     */
    public function destroy($id)
    {
        try {
            $apiKey = ApiKey::find($id);

            if (!$apiKey) {
                return $this->sendError('API key not found', [], 404);
            }

            $apiKey->revoke();

            return response()->json([
                'success' => true,
                'message' => 'API key revoked successfully'
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError('Error revoking API key', [$e->getMessage()], 500);
        }
    }
}