<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image-edit jobs (Architecture C coordinator) carry the stable ids of the owned, private
 * reference MediaAssets they were submitted with, so the worker can mint a fresh short-lived
 * provider-fetch grant per attempt without persisting a signed URL. Nullable: text-to-image
 * and all historical/legacy jobs leave it empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->json('reference_asset_ids')->nullable()->after('payload_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('image_jobs', function (Blueprint $table): void {
            $table->dropColumn('reference_asset_ids');
        });
    }
};
