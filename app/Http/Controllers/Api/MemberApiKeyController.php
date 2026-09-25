<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\PricingSetting;
use App\Models\UsageLog;
use App\Models\User;
use App\Services\Api\ApiModelCatalog;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MemberApiKeyController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $user = $this->member($request);
        $usage = UsageLog::query()->where('user_id', $user->id)->whereNotNull('api_key_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('api_key_id, COUNT(*) AS requests, SUM(cost_microusd) AS cost_microusd')->groupBy('api_key_id')->get()->keyBy('api_key_id');

        return response()->json(ApiKey::where('user_id', $user->id)->latest('id')->get()->map(fn (ApiKey $key): array => [
            ...$this->publicKey($key),
            'usage_30d' => ['requests' => (int) ($usage->get($key->id)?->requests ?? 0), 'cost_usd' => (int) ($usage->get($key->id)?->cost_microusd ?? 0) / 1_000_000],
        ]));
    }

    public function store(Request $request)
    {
        $user = $this->member($request);
        $validated = $request->validate(['name' => 'required|string|max:100']);
        $key = DB::transaction(function () use ($user, $validated): ApiKey {
            // Serialize create/revoke/delete against the owner so concurrent creates cannot pass the cap.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (ApiKey::where('user_id', $user->id)->where('is_active', true)->count() >= 10) {
                throw ValidationException::withMessages(['name' => 'Maksimal 10 kunci API aktif. Cabut kunci lama sebelum membuat yang baru.']);
            }
            $key = ApiKey::generate($user->id, $validated['name']);
            $this->audit->record($user, 'api_key.created', $key, ['name' => $key->name]);

            return $key;
        });

        return response()->json(['key' => $key->plainKey, 'api_key' => $this->publicKey($key)], 201);
    }

    public function revoke(Request $request, int $id)
    {
        $user = $this->member($request);
        $key = DB::transaction(function () use ($user, $id): ApiKey {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $key = ApiKey::where('user_id', $user->id)->findOrFail($id);
            if ($key->is_active) {
                $key->update(['is_active' => false]);
                $this->audit->record($user, 'api_key.revoked', $key);
            }

            return $key;
        });

        return response()->json(['api_key' => $this->publicKey($key)]);
    }

    public function destroy(Request $request, int $id)
    {
        $user = $this->member($request);
        DB::transaction(function () use ($user, $id): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $key = ApiKey::where('user_id', $user->id)->findOrFail($id);
            $this->audit->record($user, 'api_key.deleted', $key, ['name' => $key->name]);
            $key->delete();
        });

        return response()->noContent();
    }

    public function models(Request $request, ApiModelCatalog $catalog)
    {
        $this->member($request);

        return response()->json(['data' => $catalog->models(), 'wallet_idr_per_usd' => PricingSetting::current()->wallet_idr_per_usd]);
    }

    private function member(Request $request): User
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $user->hasPermission('ai_api'), 403, 'API access is not enabled for this account');

        return $user;
    }

    private function publicKey(ApiKey $key): array
    {
        return [
            'id' => $key->id, 'name' => $key->name, 'key_prefix' => $key->key_prefix,
            'is_active' => $key->is_active, 'rate_limit' => (int) $key->rate_limit,
            'created_at' => $key->created_at, 'last_used_at' => $key->last_used_at,
            'total_requests' => (int) $key->total_requests,
            'usage_30d' => ['requests' => 0, 'cost_usd' => 0],
        ];
    }
}
