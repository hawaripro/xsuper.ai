<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->string('template_key', 160)->nullable()->unique();
            $table->index(['is_active', 'sort_order', 'id'], 'prompt_templates_browse_index');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_templates', function (Blueprint $table) {
            $table->dropIndex('prompt_templates_browse_index');
            $table->dropUnique(['template_key']);
            $table->dropColumn('template_key');
        });
    }
};
