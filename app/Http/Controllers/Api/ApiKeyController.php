<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    /**
     * List API keys for a user (admin)
     */
    public function index(Request $request)
    {
        $userId = $request->query('user_id');
        $query = ApiKey::with('user:id,name,email');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $keys = $query->orderByDesc('created_at')->get()->map(function ($key) {
            return [
                'id' => $key->id,
                'user_id' => $key->user_id,
                'user_name' => $key->user->name ?? '-',
                'name' => $key->name,
                // Full key is never returned after creation; only a masked prefix.
                'masked_key' => $key->maskedKey(),
                'is_active' => $key->is_active,
                'rate_limit' => $key->rate_limit,
                'total_requests' => $key->total_requests,
                'last_used_at' => $key->last_used_at,
                'expires_at' => $key->expires_at,
                'created_at' => $key->created_at,
            ];
        });

        return response()->json(['keys' => $keys]);
    }

    /**
     * Generate new API key for a user (admin)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'nullable|string|max:255',
            'rate_limit' => 'nullable|integer|min:1|max:1000',
        ]);

        $apiKey = ApiKey::generate(
            $validated['user_id'],
            $validated['name'] ?? 'Default',
            ['rate_limit' => $validated['rate_limit'] ?? 60]
        );

        return response()->json([
            'message' => 'API key berhasil dibuat',
            // Shown exactly once; only the hash is stored server-side.
            'key' => $apiKey->plainKey,
            'api_key' => [
                'id' => $apiKey->id,
                'name' => $apiKey->name,
                'masked_key' => $apiKey->maskedKey(),
                'is_active' => $apiKey->is_active,
                'rate_limit' => $apiKey->rate_limit,
            ],
        ], 201);
    }

    /**
     * Toggle API key active/inactive
     */
    public function toggle(ApiKey $apiKey)
    {
        $apiKey->is_active = !$apiKey->is_active;
        $apiKey->save();

        return response()->json([
            'message' => $apiKey->is_active ? 'API key diaktifkan' : 'API key dinonaktifkan',
            'is_active' => $apiKey->is_active,
        ]);
    }

    /**
     * Delete API key
     */
    public function destroy(ApiKey $apiKey)
    {
        $apiKey->delete();
        return response()->json(['message' => 'API key berhasil dihapus']);
    }

    /**
     * Regenerate API key
     */
    public function regenerate(ApiKey $apiKey)
    {
        $apiKey->regenerateKey();

        return response()->json([
            'message' => 'API key berhasil di-regenerate',
            'key' => $apiKey->plainKey,
        ]);
    }
}
