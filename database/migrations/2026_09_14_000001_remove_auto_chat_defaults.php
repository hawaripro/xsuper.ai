<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chat_conversations')->where('model', 'au'.'to')->update(['model' => null]);
        DB::table('chat_history')->where('model', 'au'.'to')->update(['model' => null]);
    }

    public function down(): void
    {
        DB::table('chat_conversations')->whereNull('model')->update(['model' => 'au'.'to']);
    }
};
