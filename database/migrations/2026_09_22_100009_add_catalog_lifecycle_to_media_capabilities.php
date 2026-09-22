<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_capabilities', function (Blueprint $table): void {
            $table->json('source_schema')->nullable();
            $table->json('source_metadata')->nullable();
            $table->json('provider_bindings')->nullable();
            $table->json('compatibility_report')->nullable();
            $table->json('curation_overrides')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
        });
        // Preserve the migration boundary for existing published definitions, even after disable.
        DB::table('media_capabilities')->where('status', 'published')->update(['published_at' => DB::raw('updated_at')]);
        Schema::table('ai_provider_profiles', function (Blueprint $table): void {
            $table->timestamp('catalog_discovered_at')->nullable();
            $table->timestamp('authenticated_at')->nullable();
        });
        Schema::table('image_jobs', fn (Blueprint $table) => $table->json('provider_result_urls')->nullable());
        // Kinovi's previous catalog check was static documentation, not authentication.
        DB::table('ai_provider_profiles')->where('protocol', 'kinovi')->where('status', 'healthy')->update(['status' => 'discovered']);
    }

    public function down(): void
    {
        Schema::table('media_capabilities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['source_schema', 'source_metadata', 'provider_bindings', 'compatibility_report', 'curation_overrides', 'reviewed_at', 'tested_at', 'published_at', 'disabled_at']);
        });
        Schema::table('ai_provider_profiles', fn (Blueprint $table) => $table->dropColumn(['catalog_discovered_at', 'authenticated_at']));
        Schema::table('image_jobs', fn (Blueprint $table) => $table->dropColumn('provider_result_urls'));
    }
};
