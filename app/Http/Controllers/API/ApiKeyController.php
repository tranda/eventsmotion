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
            $apiKeys = ApiKey::with(self::relations())
                ->select(self::columns())
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
                'permissions.*' => 'string|in:races.bulk-update,races.read,races.write,athletes.read,athletes.write,*',
                'club_id' => 'nullable|integer|exists:clubs,id',
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

            if ($request->filled('club_id') && ApiKey::hasClubColumn()) {
                $result['model']->update(['club_id' => (int) $request->club_id]);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'api_key' => $result['api_key'], // Only show the full key once!
                    'model' => $result['model']->load(self::relations())
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
            $apiKey = ApiKey::with(self::relations())
                ->select(self::columns())
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
                'permissions.*' => 'string|in:races.bulk-update,races.read,races.write,athletes.read,athletes.write,*',
                'club_id' => 'sometimes|nullable|integer|exists:clubs,id',
                'expires_in_days' => 'nullable|integer|min:1|max:365',
                'is_active' => 'sometimes|boolean'
            ]);

            $updateData = $request->only(['name', 'permissions', 'is_active']);
            if ($request->has('club_id') && ApiKey::hasClubColumn()) {
                $updateData['club_id'] = $request->club_id;
            }

            if ($request->has('expires_in_days')) {
                if ($request->expires_in_days) {
                    $updateData['expires_at'] = Carbon::now()->addDays($request->expires_in_days);
                } else {
                    $updateData['expires_at'] = null;
                }
            }

            $apiKey->update($updateData);
            $apiKey->load(self::relations());

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

    private static function columns(): array
    {
        $cols = ['id', 'name', 'key_prefix', 'permissions', 'created_by', 'last_used_at', 'expires_at', 'is_active', 'created_at'];
        return ApiKey::hasClubColumn() ? array_merge($cols, ['club_id']) : $cols;
    }

    private static function relations(): array
    {
        return ApiKey::hasClubColumn() ? ['creator:id,name,username', 'club:id,name'] : ['creator:id,name,username'];
    }
}
