<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class ApiKey extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'key_hash',
        'key_prefix',
        'permissions',
        'created_by',
        'expires_at',
        'is_active'
    ];

    protected $casts = [
        'permissions' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_active' => 'boolean'
    ];

    protected $hidden = [
        'key_hash'
    ];

    /**
     * Generate a new API key.
     *
     * @param string $name
     * @param array $permissions
     * @param int|null $createdBy
     * @param Carbon|null $expiresAt
     * @return array ['api_key' => string, 'model' => ApiKey]
     */
    public static function generate(string $name, array $permissions = [], ?int $createdBy = null, ?Carbon $expiresAt = null): array
    {
        // Generate a secure random API key
        $apiKey = 'ak_' . Str::random(32); // ak_ prefix for identification
        $keyPrefix = substr($apiKey, 0, 8);

        $model = static::create([
            'name' => $name,
            'key_hash' => Hash::make($apiKey),
            'key_prefix' => $keyPrefix,
            'permissions' => $permissions,
            'created_by' => $createdBy,
            'expires_at' => $expiresAt,
            'is_active' => true
        ]);

        return [
            'api_key' => $apiKey,
            'model' => $model
        ];
    }

    /**
     * Verify an API key and return the model if valid.
     *
     * @param string $apiKey
     * @return static|null
     */
    public static function verify(string $apiKey): ?static
    {
        $keyPrefix = substr($apiKey, 0, 8);

        $model = static::where('key_prefix', $keyPrefix)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($model && Hash::check($apiKey, $model->key_hash)) {
            // Update last used timestamp
            $model->update(['last_used_at' => now()]);
            return $model;
        }

        return null;
    }

    /**
     * Check if the API key has a specific permission.
     *
     * @param string $permission
     * @return bool
     */
    public function hasPermission(string $permission): bool
    {
        if (empty($this->permissions)) {
            return false;
        }

        return in_array($permission, $this->permissions) || in_array('*', $this->permissions);
    }

    /**
     * Revoke (deactivate) the API key.
     *
     * @return bool
     */
    public function revoke(): bool
    {
        return $this->update(['is_active' => false]);
    }

    /**
     * Relationship to the user who created this API key.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope for active keys only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }
}