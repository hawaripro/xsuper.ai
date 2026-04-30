<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    // Admin: list devices for a user
    public function index(Request $request)
    {
        $userId = $request->query('user_id');
        $query = UserDevice::with('user:id,name,email');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $devices = $query->orderByDesc('last_active_at')->get()->map(function ($d) {
            return [
                'id' => $d->id,
                'user_id' => $d->user_id,
                'user_name' => $d->user->name ?? '-',
                'device_name' => $d->device_name,
                'device_type' => $d->device_type,
                'ip_address' => $d->ip_address,
                'status' => $d->status,
                'last_active_at' => $d->last_active_at,
                'created_at' => $d->created_at,
            ];
        });

        return response()->json(['devices' => $devices]);
    }

    // Admin: update device status (active/blocked)
    public function update(Request $request, UserDevice $device)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:active,blocked,pending',
        ]);

        $device->status = $validated['status'];
        $device->save();

        return response()->json(['message' => 'Device status diperbarui', 'device' => $device]);
    }

    // Admin: delete device
    public function destroy(UserDevice $device)
    {
        $device->delete();
        return response()->json(['message' => 'Device dihapus']);
    }
}
