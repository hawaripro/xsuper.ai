<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StorageQuotaService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function index()
    {
        $users = User::orderByDesc('created_at')->get([
            'id', 'name', 'email', 'role', 'expires_at', 'permissions', 'created_at', 'updated_at',
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
        if ($validated['role'] !== 'admin' && ! empty($validated['duration']) && $validated['duration'] !== 'unlimited') {
            $expiresAt = $this->calcExpiry($validated['duration']);
        }

        $permissions = $validated['role'] !== 'admin' && isset($validated['permissions'])
            ? $validated['permissions']
            : null;

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'expires_at' => $expiresAt,
            'permissions' => $permissions,
        ]);

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

        if ($validated['role'] === 'admin') {
            $user->expires_at = null;
            $user->permissions = null;
        } else {
            if (isset($validated['duration'])) {
                if ($validated['duration'] === 'unlimited' || $validated['duration'] === 'clear') {
                    $user->expires_at = null;
                } elseif ($validated['duration'] !== '') {
                    $base = $user->expires_at && $user->expires_at->isFuture() ? $user->expires_at : now();
                    $user->expires_at = $this->calcExpiry($validated['duration'], $base);
                }
            }
            if (isset($validated['permissions'])) {
                $user->permissions = $validated['permissions'];
            }
        }

        if (! empty($validated['password'])) {
            // Persist the pending profile changes with credential rotation and target-session revocation.
            $user->replacePassword($validated['password']);
        } else {
            $user->save();
        }

        return response()->json(['user' => $user, 'message' => 'User berhasil diperbarui']);
    }

    public function destroy(User $user, StorageQuotaService $storage)
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Tidak dapat menghapus akun sendiri'], 422);
        }

        $storage->deleteAccount($user);

        return response()->json(['message' => 'User berhasil dihapus']);
    }

    private function calcExpiry(string $duration, ?Carbon $base = null): Carbon
    {
        $base ??= now();

        return match ($duration) {
            '1d' => $base->copy()->addDay(),
            '7d' => $base->copy()->addDays(7),
            '30d' => $base->copy()->addDays(30),
            '90d' => $base->copy()->addDays(90),
            '180d' => $base->copy()->addDays(180),
            '365d' => $base->copy()->addDays(365),
            default => $base->copy()->addMonth(),
        };
    }
}
