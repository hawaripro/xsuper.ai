<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Standard/MAX (upstream "Original"/"Authentic") model tier system was
 * removed: every enabled and available model is now visible by category alone.
 * Drop the storage column and strip the two dead per-user permission flags that
 * used to gate it, mirroring the earlier retire_unused_model_tiers cleanup.
 */
return new class extends Migration
{
    private const DEAD_PERMISSIONS = ['model_original', 'model_authentic'];

    public function up(): void
    {
        if (Schema::hasColumn('ai_model_profiles', 'tier')) {
            Schema::table('ai_model_profiles', function (Blueprint $table): void {
                $table->dropColumn('tier');
            });
        }

        DB::table('users')->whereNotNull('permissions')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $permissions = json_decode((string) $user->permissions, true);
                if (! is_array($permissions)) {
                    continue;
                }
                $cleaned = array_diff_key($permissions, array_flip(self::DEAD_PERMISSIONS));
                if (count($cleaned) !== count($permissions)) {
                    DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode($cleaned)]);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ai_model_profiles', 'tier')) {
            Schema::table('ai_model_profiles', function (Blueprint $table): void {
                $table->string('tier', 40)->nullable()->after('category');
            });
        }
    }
};
