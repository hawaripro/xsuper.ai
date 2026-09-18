<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_model_profiles', function (Blueprint $table): void {
            $table->unsignedInteger('token_cost')->nullable()->after('max_output_tokens');
            $table->json('generation_config')->nullable()->after('token_cost');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_profiles', function (Blueprint $table): void {
            $table->dropColumn(['token_cost', 'generation_config']);
        });
    }
};
