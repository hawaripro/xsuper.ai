<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_model_profiles', function (Blueprint $table): void {
            $table->string('provider_name', 120)->nullable()->after('display_name');
            $table->text('description_id')->nullable()->after('tier');
            $table->text('description_en')->nullable()->after('description_id');
            $table->string('logo_url', 255)->nullable()->after('description_en');
            $table->unsignedInteger('context_window')->nullable()->after('logo_url');
            $table->unsignedInteger('max_output_tokens')->nullable()->after('context_window');
            $table->json('input_modalities')->nullable()->after('capabilities');
            $table->json('output_modalities')->nullable()->after('input_modalities');
            $table->json('badges')->nullable()->after('output_modalities');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('badges');
            $table->index(['is_enabled', 'sort_order'], 'ai_model_profiles_public_order');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_profiles', function (Blueprint $table): void {
            $table->dropIndex('ai_model_profiles_public_order');
            $table->dropColumn([
                'provider_name',
                'description_id',
                'description_en',
                'logo_url',
                'context_window',
                'max_output_tokens',
                'input_modalities',
                'output_modalities',
                'badges',
                'sort_order',
            ]);
        });
    }
};
