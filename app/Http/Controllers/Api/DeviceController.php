<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends Controller
{
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

    public function update(Request $request, UserDevice $device)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:active,blocked,pending',
        ]);

        $device->status = $validated['status'];
        $device->save();

        // If blocked, kill user's sessions
        if ($validated['status'] === 'blocked') {
            $this->killUserSessions($device->user_id);
        }

        return response()->json(['message' => 'Device status diperbarui', 'device' => $device]);
    }

    public function destroy(UserDevice $device)
    {
        $userId = $device->user_id;
        $device->delete();

        // Kill user's sessions so they get logged out
        $this->killUserSessions($userId);

        return response()->json(['message' => 'Device dihapus']);
    }

    /**
     * Kill all sessions for a user (force logout)
     */
    private function killUserSessions(int $userId): void
    {
        // Delete sessions from database (session driver = database)
        DB::table('sessions')->where('user_id', $userId)->delete();
    }
}
