<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_tool_jobs', function (Blueprint $table): void {
            // Token billing for paid tools (rembg). null/admin = free; tokens = member reservation.
            $table->string('billing_mode', 16)->nullable()->after('options');
            $table->unsignedInteger('tokens_reserved')->default(0)->after('billing_mode');
        });
    }

    public function down(): void
    {
        Schema::table('media_tool_jobs', function (Blueprint $table): void {
            $table->dropColumn(['billing_mode', 'tokens_reserved']);
        });
    }
};
