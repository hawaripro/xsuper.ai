<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ChatProController extends Controller
{
    private function baseUrl(): string
    {
        return rtrim(config('services.openwebui.url', 'https://chat.ultrai.id'), '/');
    }

    private function apiKey(): string
    {
        return config('services.openwebui.key', '');
    }

    /**
     * List all OpenWebUI users
     */
    public function users()
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Accept' => 'application/json',
            ])->get($this->baseUrl() . '/api/v1/users/');

            if ($response->successful()) {
                return response()->json(['users' => $response->json()]);
            }

            return response()->json(['users' => [], 'error' => 'Failed to fetch'], $response->status());
        } catch (\Exception $e) {
            return response()->json(['users' => [], 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Force logout (delete) a user session
     */
    public function forceLogout(string $userId)
    {
        try {
            // OpenWebUI: update user to deactivate or delete session
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Accept' => 'application/json',
            ])->delete($this->baseUrl() . '/api/v1/users/' . $userId);

            if ($response->successful()) {
                return response()->json(['success' => true]);
            }

            return response()->json(['success' => false, 'error' => 'Failed'], $response->status());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a user from OpenWebUI entirely
     */
    public function deleteUser(string $userId)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey(),
                'Accept' => 'application/json',
            ])->delete($this->baseUrl() . '/api/v1/users/' . $userId);

            if ($response->successful()) {
                return response()->json(['success' => true]);
            }

            return response()->json(['success' => false, 'error' => 'Failed'], $response->status());
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
