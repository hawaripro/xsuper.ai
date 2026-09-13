<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('billing_reserved_microusd')->default(0)->after('tokens_used');
            $table->string('billing_reference_id', 160)->nullable()->after('billing_reserved_microusd');
            $table->string('billing_status', 20)->default('none')->after('billing_reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropColumn(['billing_reserved_microusd', 'billing_reference_id', 'billing_status']);
        });
    }
};
