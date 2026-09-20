<?php

namespace App\Http\Middleware;

use App\Services\StorageQuotaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks new media that would be stored once a member is at or over their
 * Library quota. Admins are exempt. The member must download and delete items
 * (or buy a storage upgrade) to free space, matching the "wajib download" rule.
 */
class EnsureStorageAvailable
{
    public function __construct(private readonly StorageQuotaService $storage) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $this->storage->exceeded($user)) {
            return response()->json([
                'message' => 'Penyimpanan Library penuh. Unduh lalu hapus beberapa item, atau tingkatkan penyimpanan untuk melanjutkan.',
                'code' => 'storage_full',
                'storage' => $this->storage->summary($user),
            ], 413);
        }

        return $next($request);
    }
}
