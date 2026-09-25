<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('margin_pct', 5, 2)->default(40);
            $table->decimal('llm_margin_pct', 5, 2)->nullable();
            $table->decimal('buffer_pct', 5, 2)->default(10);
            $table->decimal('payment_fee_pct', 5, 2)->default(1);
            $table->unsignedInteger('wallet_idr_per_usd')->default(16000);
            $table->unsignedInteger('default_cost_idr_per_usd')->default(19000);
            $table->boolean('round_tokens')->default(true);
            $table->unsignedInteger('chat_output_cap')->default(8192);
            $table->timestamp('last_applied_at')->nullable();
            $table->foreignId('last_applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Seed the singleton so reads never need a null guard. The wallet rate continues the deposit rate in force today.
        DB::table('pricing_settings')->insert([
            'id' => 1,
            'margin_pct' => 40,
            'llm_margin_pct' => null,
            'buffer_pct' => 10,
            'payment_fee_pct' => 1,
            'wallet_idr_per_usd' => (int) config('deposits.idr_per_usd', 16000),
            'default_cost_idr_per_usd' => 19000,
            'round_tokens' => true,
            'chat_output_cap' => 8192,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_settings');
    }
};
