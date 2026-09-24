<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A workspace request for several native images runs as one image job per image (the capability
 * coordinator generates one image per job). Those jobs share the request's key so the studio can
 * present them together as variations. Additive and nullable: existing jobs are single requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->char('batch_key', 64)->nullable();
            $table->index(['user_id', 'batch_key']);
        });
    }

    public function down(): void
    {
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'batch_key']);
            $table->dropColumn('batch_key');
        });
    }
};
