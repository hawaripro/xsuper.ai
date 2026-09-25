<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table): void {
            $table->text('provider_result_url')->nullable();
        });
        Schema::table('audio_jobs', function (Blueprint $table): void {
            $table->json('provider_result_urls')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table): void {
            $table->dropColumn('provider_result_url');
        });
        Schema::table('audio_jobs', function (Blueprint $table): void {
            $table->dropColumn('provider_result_urls');
        });
    }
};
