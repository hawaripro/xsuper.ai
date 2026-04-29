<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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
                if ($validated['duration'] === 'clear' || $validated['duration'] === 'unlimited') {
                    $user->expires_at = null;
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
}
