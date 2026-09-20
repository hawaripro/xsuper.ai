<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Codex, Wavespeed, YepAPI and Canva were retired, leaving only Standard
 * (upstream "Original") and MAX (upstream "Authentic").
 *
 * Tier strings outside the known set are dropped by
 * AiProxyService::filterModelsForTiers, so any row left on a retired tier would
 * silently vanish from the workspace picker. Fold them onto Standard and strip
 * the matching per-user permission flags.
 */
return new class extends Migration
{
    private const RETIRED_TIERS = ['Codex', 'Wavespeed', 'YepAPI', 'Canva'];

    private const RETIRED_PERMISSIONS = ['model_codex', 'model_wavespeed', 'model_yepapi', 'model_canva'];

    public function up(): void
    {
        DB::table('ai_model_profiles')
            ->whereIn('tier', self::RETIRED_TIERS)
            ->update(['tier' => 'Standard']);

        DB::table('users')->whereNotNull('permissions')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $permissions = json_decode((string) $user->permissions, true);
                if (! is_array($permissions)) {
                    continue;
                }
                $cleaned = array_diff_key($permissions, array_flip(self::RETIRED_PERMISSIONS));
                if (count($cleaned) !== count($permissions)) {
                    DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode($cleaned)]);
                }
            }
        });
    }

    public function down(): void
    {
        // Retired tiers carry no recoverable meaning; folding them onto Standard
        // is deliberately one-way.
    }
};
