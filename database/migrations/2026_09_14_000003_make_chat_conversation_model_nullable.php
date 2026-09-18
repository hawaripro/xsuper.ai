<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->string('model')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->table('chat_conversations')->whereNull('model')->exists()) {
            throw new RuntimeException('Cannot roll back conversation model nullability while conversations have no selected model.');
        }

        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->string('model')->nullable(false)->change();
        });
    }
};
