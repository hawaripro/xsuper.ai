<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('onboarding_mode', 50)->nullable()->after('google_id');
        });

        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50); // Coding, UMKM, Konten, Marketplace, Excel, Desain, Prompt Gambar, Prompt Video, Bisnis, Belajar
            $table->string('title');
            $table->text('prompt_text');
            $table->string('mode', 50)->nullable(); // links to onboarding_mode
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'is_active']);
            $table->index(['mode', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_templates');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_mode');
        });
    }
};
