<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('conversation_id', 100);
            $table->uuid('client_request_id');
            $table->char('fingerprint', 64);
            $table->string('model', 160);
            $table->string('status', 20)->default('queued');
            $table->unsignedBigInteger('user_message_id')->nullable();
            $table->unsignedBigInteger('assistant_message_id')->nullable();
            $table->unsignedBigInteger('retry_of')->nullable();
            $table->unsignedBigInteger('continuation_of')->nullable();
            $table->json('attachment_ids')->nullable();
            $table->json('context_snapshot');
            $table->longText('partial_content');
            $table->json('usage')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('stop_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('usage_recorded_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'client_request_id'], 'chat_operation_request_unique');
            $table->index(['user_id', 'conversation_id', 'status'], 'chat_operation_conversation_state');
            $table->index(['status', 'heartbeat_at']);
        });
        Schema::table('chat_history', function (Blueprint $table) {
            $table->uuid('operation_id')->nullable()->index();
            $table->string('status', 20)->default('completed');
            $table->json('attachment_ids')->nullable();
            $table->string('model', 160)->nullable()->change();
            $table->longText('content')->change();
        });
        Schema::table('usage_logs', function (Blueprint $table) {
            $table->string('model', 160)->change();
        });
        if (DB::getDriverName() === 'pgsql') {
            // ALTER COLUMN ... TYPE resets a column to its type's default collation. Keep the case/accent-insensitive
            // collation 2026_09_16_100005 gave these columns, which history and dashboard search rely on.
            foreach ([['chat_history', 'model', 'varchar(160)'], ['chat_history', 'content', 'text'], ['usage_logs', 'model', 'varchar(160)']] as [$table, $column, $type]) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE {$type} COLLATE public.xsuper_unicode_ci");
            }
        }
    }

    public function down(): void
    {
        Schema::table('chat_history', function (Blueprint $table) {
            $table->dropIndex(['operation_id']);
            $table->dropColumn(['operation_id', 'status', 'attachment_ids']);
        });
        Schema::dropIfExists('chat_operations');
    }
};
