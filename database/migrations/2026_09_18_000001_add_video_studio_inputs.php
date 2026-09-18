<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table): void {
            $table->boolean('pro_mode')->default(false);
            $table->boolean('has_reference')->default(false);
            $table->string('reference_path')->nullable();
            $table->string('reference_mime_type', 32)->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('video_jobs')->where('pro_mode', true)->orWhere('has_reference', true)->exists()) {
            throw new RuntimeException('Cannot roll back video studio inputs while purchased quality or private reference history exists.');
        }

        Schema::table('video_jobs', function (Blueprint $table): void {
            $table->dropColumn(['pro_mode', 'has_reference', 'reference_path', 'reference_mime_type']);
        });
    }
};
