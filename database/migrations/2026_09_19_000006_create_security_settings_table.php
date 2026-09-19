<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enforce_admin_ip')->default(false);
            $table->json('admin_ip_allowlist')->nullable();
            $table->boolean('require_admin_2fa')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Seed the singleton row so reads never need a null guard.
        DB::table('security_settings')->insert([
            'id' => 1,
            'enforce_admin_ip' => false,
            'admin_ip_allowlist' => json_encode([]),
            'require_admin_2fa' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('security_settings');
    }
};
