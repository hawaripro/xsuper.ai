<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Native fal LongCat video endpoints (text and reference) that became per-second. */
    private const FAL_VIDEO = [
        'fal-ai/longcat-video/distilled/text-to-video/480p',
        'fal-ai/longcat-video/distilled/image-to-video/480p',
    ];

    /** Native Kinovi video slugs that became per-second. */
    private const KINOVI_VIDEO = [
        'seedance2-5', 'seedance-20', 'seedance2-fast', 'seedance2.0-mini',
        'wan3.0-text-to-video', 'wan3.0-prime-text-to-video',
    ];

    public function up(): void
    {
        Schema::table('ai_model_profiles', function (Blueprint $table): void {
            // The unit the stored token_cost is billed in on the native path; NULL follows the native config.
            $table->string('token_cost_unit', 16)->nullable();
        });
        // Existing positive video tariffs were charged once per generation before these
        // integrations became per-second; they keep that unit until a reviewed apply converts them.
        foreach (['fal' => self::FAL_VIDEO, 'kinovi' => self::KINOVI_VIDEO] as $protocol => $routes) {
            DB::table('ai_model_profiles')
                ->whereIn('provider_id', DB::table('ai_provider_profiles')->where('protocol', $protocol)->select('id'))
                ->where('token_cost', '>', 0)->whereNull('token_cost_unit')
                ->whereIn(DB::raw("COALESCE(NULLIF(upstream_model_id, ''), model_id)"), $routes)
                ->update(['token_cost_unit' => 'generation']);
        }
    }

    public function down(): void
    {
        Schema::table('ai_model_profiles', fn (Blueprint $table) => $table->dropColumn('token_cost_unit'));
    }
};
