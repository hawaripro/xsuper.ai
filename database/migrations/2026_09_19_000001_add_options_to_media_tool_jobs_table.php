<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_tool_jobs', function (Blueprint $table) {
            // Validated processing options (download quality, resolution, trim, bitrate).
            $table->json('options')->nullable()->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('media_tool_jobs', function (Blueprint $table) {
            $table->dropColumn('options');
        });
    }
};
