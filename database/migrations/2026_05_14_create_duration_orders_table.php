<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duration_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('package'); // 1_day, 1_week, 1_month, 3_months, 6_months, 12_months
            $table->integer('days'); // duration in days
            $table->integer('price'); // in IDR
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('note')->nullable(); // admin note
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('duration_orders');
    }
};
