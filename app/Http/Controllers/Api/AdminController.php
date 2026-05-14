<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

class AdminController extends Controller
{
    public function index()
    {
        $users = User::orderByDesc('created_at')->get([
            'id', 'name', 'email', 'role', 'expires_at', 'permissions', 'created_at', 'updated_at'
        ])->map(function ($user) {
            $user->is_expired = $user->isExpired();
            $user->days_remaining = $user->daysRemaining();
            $user->active_permissions = $user->getPermissions();
            return $user;
        });

        return response()->json(['users' => $users]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|string|in:admin,member',
            'duration' => 'nullable|string|in:1d,7d,30d,90d,180d,365d,unlimited',
            'permissions' => 'nullable|array',
        ]);

        $expiresAt = null;
        if ($validated['role'] !== 'admin' && !empty($validated['duration']) && $validated['duration'] !== 'unlimited') {
            $expiresAt = $this->calcExpiry($validated['duration']);
        }

        $permissions = null;
        if ($validated['role'] !== 'admin' && isset($validated['permissions'])) {
            $permissions = $validated['permissions'];
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'expires_at' => $expiresAt,
            'permissions' => $permissions,
        ]);

        // Auto-create user di OpenWebUI (chat.ultrai.id)
        $this->syncCreateOpenWebUI($validated['name'], $validated['email'], $validated['password'], $validated['role']);

        return response()->json(['user' => $user, 'message' => 'User berhasil dibuat'], 201);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role' => 'required|string|in:admin,member',
            'duration' => 'nullable|string|in:1d,7d,30d,90d,180d,365d,unlimited,clear',
            'permissions' => 'nullable|array',
        ]);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->role = $validated['role'];

        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }

        // Handle duration
        if ($validated['role'] === 'admin') {
            $user->expires_at = null;
            $user->permissions = null;
        } else {
            if (!empty($validated['duration'])) {
                if ($validated['duration'] === 'unlimited') {
                    $user->expires_at = null;
                } elseif ($validated['duration'] === 'clear') {
                    // Reset to expired (set to past time)
                    $user->expires_at = now()->subMinute();
                } else {
                    $base = ($user->expires_at && $user->expires_at->isFuture()) ? $user->expires_at : now();
                    $user->expires_at = $this->calcExpiry($validated['duration'], $base);
                }
            }

            // Update permissions
            if (isset($validated['permissions'])) {
                $user->permissions = $validated['permissions'];
            }
        }

        $user->save();

        return response()->json(['user' => $user, 'message' => 'User berhasil diperbarui']);
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Tidak bisa menghapus akun sendiri'], 403);
        }

        // Auto-delete user dari OpenWebUI
        $this->syncDeleteOpenWebUI($user->email);

        $user->delete();

        return response()->json(['message' => 'User berhasil dihapus']);
    }

    private function calcExpiry(string $duration, ?Carbon $base = null): Carbon
    {
        $base = $base ?? now();
        return match ($duration) {
            '1d' => $base->copy()->addDay(),
            '7d' => $base->copy()->addWeek(),
            '30d' => $base->copy()->addMonth(),
            '90d' => $base->copy()->addMonths(3),
            '180d' => $base->copy()->addMonths(6),
            '365d' => $base->copy()->addYear(),
            default => $base->copy()->addMonth(),
        };
    }

    /**
     * Create user di OpenWebUI dengan email + password yang sama.
     */
    private function syncCreateOpenWebUI(string $name, string $email, string $password, string $role): void
    {
        try {
            $baseUrl = rtrim(config('services.openwebui.url', 'https://chat.ultrai.id'), '/');
            $apiKey = config('services.openwebui.key', '');

            if (empty($apiKey)) return;

            Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->post($baseUrl . '/api/v1/auths/add', [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'role' => $role === 'admin' ? 'admin' : 'user',
            ]);
        } catch (\Exception $e) {
            // Silently fail — don't block user creation
            \Log::warning('OpenWebUI sync create failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete user dari OpenWebUI berdasarkan email.
     */
    private function syncDeleteOpenWebUI(string $email): void
    {
        try {
            $baseUrl = rtrim(config('services.openwebui.url', 'https://chat.ultrai.id'), '/');
            $apiKey = config('services.openwebui.key', '');

            if (empty($apiKey)) return;

            // First find user ID by email
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->get($baseUrl . '/api/v1/users/');

            if ($response->successful()) {
                $data = $response->json();
                $users = $data['users'] ?? $data ?? [];

                foreach ($users as $u) {
                    if (($u['email'] ?? '') === $email) {
                        // Delete by ID
                        Http::withHeaders([
                            'Authorization' => 'Bearer ' . $apiKey,
                        ])->delete($baseUrl . '/api/v1/users/' . $u['id']);
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::warning('OpenWebUI sync delete failed: ' . $e->getMessage());
        }
    }
}
