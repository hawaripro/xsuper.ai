<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            // Signals captured when the referred account is attributed, compared
            // against the referrer's known devices to hold duplicate-account rewards.
            $table->string('referred_ip', 45)->nullable()->after('status');
            $table->string('referred_device_hash', 64)->nullable()->after('referred_ip');
            $table->string('risk_level', 16)->default('clear')->after('referred_device_hash');
            $table->json('risk_reasons')->nullable()->after('risk_level');
            $table->timestamp('reviewed_at')->nullable()->after('qualified_at');
            $table->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->string('review_note', 240)->nullable()->after('reviewed_by');
            $table->index(['status', 'risk_level']);
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropIndex(['status', 'risk_level']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['referred_ip', 'referred_device_hash', 'risk_level', 'risk_reasons', 'reviewed_at', 'review_note']);
        });
    }
};
