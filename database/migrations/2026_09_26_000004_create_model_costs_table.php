<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_model_profile_id')->unique()->constrained('ai_model_profiles')->cascadeOnDelete();
            $table->string('source', 24);
            $table->string('currency', 8)->default('usd');
            foreach (['input_per_million', 'output_per_million', 'cache_read_per_million', 'cache_write_per_million', 'unit_cost'] as $column) {
                $table->decimal($column, 20, 10)->nullable();
            }
            $table->string('unit', 16)->nullable();
            $table->string('status', 12)->default('unknown');
            $table->string('basis_note', 500)->nullable();
            $table->string('reference_id', 191)->nullable();
            $table->boolean('price_locked')->default(false);
            $table->timestamp('fetched_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_costs');
    }
};
