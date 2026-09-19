<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('email_otp_hash')->nullable()->after('email_provider');
            $table->timestamp('email_otp_expires_at')->nullable()->after('email_otp_hash');
            $table->timestamp('email_otp_sent_at')->nullable()->after('email_otp_expires_at');
            $table->unsignedTinyInteger('email_otp_attempts')->default(0)->after('email_otp_sent_at');
        });

        // Accounts that existed before OTP activation keep their access.
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_otp_hash', 'email_otp_expires_at', 'email_otp_sent_at', 'email_otp_attempts']);
        });
    }
};
