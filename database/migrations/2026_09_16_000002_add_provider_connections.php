<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_profiles', function (Blueprint $table): void {
            $table->string('protocol', 20)->default('openai');
            $table->string('base_url', 2048)->nullable();
            $table->text('api_key')->nullable();
            $table->string('api_version', 32)->default('2023-06-01');
        });

        Schema::table('ai_model_profiles', function (Blueprint $table): void {
            $table->string('upstream_model_id', 160)->nullable();
            $identity = $table->string('upstream_identity', 160);
            // SQLite can add a virtual column to populated tables, but not a stored column.
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $identity->virtualAs('COALESCE(upstream_model_id, model_id)');
            } else {
                $identity->storedAs('COALESCE(upstream_model_id, model_id)');
            }
            $table->unique(['provider_id', 'upstream_identity'], 'ai_models_provider_upstream_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_profiles', function (Blueprint $table): void {
            $table->dropUnique('ai_models_provider_upstream_unique');
            $table->dropColumn('upstream_identity');
            $table->dropColumn('upstream_model_id');
        });
        Schema::table('ai_provider_profiles', function (Blueprint $table): void {
            $table->dropColumn(['protocol', 'base_url', 'api_key', 'api_version']);
        });
    }
};
