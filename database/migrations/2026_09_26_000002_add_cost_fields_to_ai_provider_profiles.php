<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_provider_profiles', function (Blueprint $table): void {
            $table->string('cost_currency', 8)->default('usd');
            $table->decimal('cost_idr_per_unit', 14, 4)->nullable();
            $table->string('cost_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_profiles', fn (Blueprint $table) => $table->dropColumn(['cost_currency', 'cost_idr_per_unit', 'cost_note']));
    }
};
