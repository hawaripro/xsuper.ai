<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            // NULL permits multiple regular workspaces; personal is unique per owner.
            $table->string('default_key', 20)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'default_key']);
        });

        Schema::table('chat_conversations', function (Blueprint $table) {
            // Existing numeric chat_messages records are deliberately not web-chat keys.
            $table->string('conversation_key', 100)->nullable();
            $table->foreignId('workspace_id')->nullable()->constrained('chat_workspaces')->nullOnDelete();
            $table->boolean('pinned')->default(false);
            $table->boolean('title_is_custom')->default(false);
            // A retained tombstone prevents a late streaming callback from recreating history.
            $table->timestamp('deleted_at')->nullable();
            $table->unique(['user_id', 'conversation_key']);
            $table->index(['user_id', 'workspace_id', 'deleted_at']);
        });

        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('chat_workspaces')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignUuid('asset_id')->constrained('media_assets')->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('detached_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'conversation_id', 'detached_at']);
            $table->unique(['conversation_id', 'asset_id']);
        });

        DB::table('users')->whereExists(function ($query) {
            $query->selectRaw('1')->from('chat_history')
                ->whereColumn('chat_history.user_id', 'users.id')->where('conversation_id', '<>', '');
        })->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                DB::transaction(function () use ($user): void {
                    $workspaceId = DB::table('chat_workspaces')->insertGetId([
                        'user_id' => $user->id, 'name' => 'Personal', 'notes' => '', 'version' => 1,
                        'default_key' => 'personal', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('chat_conversations')->insertUsing(
                        ['user_id', 'workspace_id', 'conversation_key', 'title', 'model', 'created_at', 'updated_at'],
                        DB::table('chat_history')->where('user_id', $user->id)->where('conversation_id', '<>', '')
                            ->select('user_id')->selectRaw('? as workspace_id', [$workspaceId])
                            ->addSelect('conversation_id')->selectRaw('? as title, NULL as model', ['New Chat'])
                            ->selectRaw('MIN(created_at) as created_at, MAX(created_at) as updated_at')
                            ->groupBy('user_id', 'conversation_id'),
                    );
                });
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachments');
        // Remove only metadata created for live history; the live messages remain untouched.
        DB::table('chat_conversations')->whereNotNull('conversation_key')->delete();
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'conversation_key']);
            $table->dropIndex(['user_id', 'workspace_id', 'deleted_at']);
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['conversation_key', 'pinned', 'title_is_custom', 'deleted_at']);
        });
        Schema::dropIfExists('chat_workspaces');
    }
};
