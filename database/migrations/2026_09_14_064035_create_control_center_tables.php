<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            DB::table('users')->whereNotNull('permissions')->orderBy('id')->each(function (object $user): void {
                $permissions = json_decode($user->permissions, true);
                if (! is_array($permissions) || ! array_key_exists('chat_ai_pro', $permissions)) {
                    return;
                }
                unset($permissions['chat_ai_pro']);
                DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode($permissions)]);
            });
        }

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 40)->default('system');
                $table->string('title', 160);
                $table->text('body');
                $table->string('action_url')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'read_at', 'created_at']);
            });
        }

        if (! Schema::hasTable('referrals')) {
            Schema::create('referrals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('referred_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->string('code', 32);
                $table->string('status', 24)->default('attributed');
                $table->timestamp('attributed_at');
                $table->timestamp('qualified_at')->nullable();
                $table->timestamps();
                $table->index(['referrer_id', 'status']);
            });
        }

        if (! Schema::hasTable('referral_rewards')) {
            Schema::create('referral_rewards', function (Blueprint $table) {
                $table->id();
                $table->foreignId('referral_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('kind', 24);
                $table->unsignedInteger('days')->default(0);
                $table->unsignedBigInteger('wallet_microusd')->default(0);
                $table->string('reference', 120)->unique();
                $table->timestamp('awarded_at');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('feedback')) {
            Schema::create('feedback', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('rating');
                $table->string('category', 40);
                $table->text('message');
                $table->string('status', 24)->default('new');
                $table->boolean('is_testimonial')->default(false);
                $table->text('admin_note')->nullable();
                $table->timestamps();
                $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->string('subject', 160);
                $table->string('category', 40);
                $table->string('priority', 16)->default('normal');
                $table->string('status', 24)->default('open');
                $table->timestamp('last_replied_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'priority', 'updated_at']);
            });
            Schema::create('support_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->text('body');
                $table->boolean('is_staff')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('content_blocks')) {
            Schema::create('content_blocks', function (Blueprint $table) {
                $table->id();
                $table->string('key', 80);
                $table->string('locale', 5)->default('id');
                $table->json('draft');
                $table->json('published')->nullable();
                $table->boolean('is_published')->default(false);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->unique(['key', 'locale']);
            });
        }

        if (! Schema::hasTable('analytics_events')) {
            Schema::create('analytics_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name', 64);
                $table->string('session_id', 80)->nullable();
                $table->json('properties')->nullable();
                $table->string('path', 255)->nullable();
                $table->timestamps();
                $table->index(['name', 'created_at']);
            });
        }

        if (! Schema::hasTable('audit_events')) {
            Schema::create('audit_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 96);
                $table->nullableMorphs('subject');
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
                $table->index(['action', 'created_at']);
            });
        }

        if (! Schema::hasTable('ai_provider_profiles')) {
            Schema::create('ai_provider_profiles', function (Blueprint $table) {
                $table->id();
                $table->string('slug', 64)->unique();
                $table->string('name', 120);
                $table->string('status', 24)->default('unknown');
                $table->boolean('is_enabled')->default(true);
                $table->json('capabilities')->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
            });
            Schema::create('ai_model_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('provider_id')->nullable()->constrained('ai_provider_profiles')->nullOnDelete();
                $table->string('model_id', 160)->unique();
                $table->string('display_name', 160);
                $table->string('category', 32)->default('chat');
                $table->string('tier', 40)->nullable();
                $table->boolean('is_enabled')->default(true);
                $table->boolean('is_available')->default(false);
                $table->json('capabilities')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->index(['category', 'is_enabled']);
            });
        }

        if (! Schema::hasTable('image_jobs')) {
            Schema::create('image_jobs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->uuid('job_id')->unique();
                $table->string('model', 160);
                $table->text('prompt');
                $table->string('size', 24)->default('1024x1024');
                $table->unsignedTinyInteger('quantity')->default(1);
                $table->string('status', 24)->default('pending');
                $table->json('result_urls')->nullable();
                $table->text('error_message')->nullable();
                $table->unsignedBigInteger('billing_reserved_microusd')->default(0);
                $table->string('billing_reference_id', 160)->nullable()->unique();
                $table->string('billing_status', 24)->default('not_required');
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('image_jobs');
        Schema::dropIfExists('ai_model_profiles');
        Schema::dropIfExists('ai_provider_profiles');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('analytics_events');
        Schema::dropIfExists('content_blocks');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('feedback');
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('notifications');
    }
};
