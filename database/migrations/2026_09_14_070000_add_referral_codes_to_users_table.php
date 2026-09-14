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
            $table->string('referral_code', 16)->nullable()->unique()->after('email');
        });

        DB::table('users')
            ->select('id')
            ->whereNull('referral_code')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    DB::table('users')->where('id', $user->id)->update([
                        'referral_code' => $this->uniqueCode(),
                    ]);
                }
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('referral_code', 16)->nullable(false)->change();
        });

        Schema::create('referral_program_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('reward_days')->default(3);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_program_settings');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['referral_code']);
            $table->dropColumn('referral_code');
        });
    }

    private function uniqueCode(): string
    {
        do {
            $code = 'UTR-'.strtoupper(bin2hex(random_bytes(6)));
        } while (DB::table('users')->where('referral_code', $code)->exists());

        return $code;
    }
};
