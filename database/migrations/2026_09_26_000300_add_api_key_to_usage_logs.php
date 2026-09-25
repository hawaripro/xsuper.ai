<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_logs', function (Blueprint $table): void {
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->index(['api_key_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('usage_logs', function (Blueprint $table): void {
            $table->dropIndex(['api_key_id', 'created_at']);
            $table->dropConstrainedForeignId('api_key_id');
        });
    }
};
