<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The permission editor must mirror the dashboard pages exactly:
 *  - `ai_dashboard` / `ai_dashboard_official` were defined and rendered but no
 *    code path ever consulted them — dead switches that only misled admins.
 *  - `image_generator` is new: the Generate Image page previously had no
 *    permission at all, so grant it to every existing user to preserve access.
 */
return new class extends Migration
{
    private const DEAD = ['ai_dashboard', 'ai_dashboard_official', 'model_codex', 'model_wavespeed', 'model_yepapi', 'model_canva'];

    public function up(): void
    {
        DB::table('users')->whereNotNull('permissions')->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                $permissions = json_decode((string) $user->permissions, true);
                if (! is_array($permissions)) {
                    continue;
                }
                $cleaned = array_diff_key($permissions, array_flip(self::DEAD));
                $cleaned['image_generator'] = array_key_exists('image_generator', $cleaned)
                    ? (bool) $cleaned['image_generator']
                    : true;
                if ($cleaned !== $permissions) {
                    DB::table('users')->where('id', $user->id)->update(['permissions' => json_encode($cleaned)]);
                }
            }
        });
    }

    public function down(): void
    {
        // Dead flags carry no recoverable meaning and image access was
        // previously ungated; there is nothing sensible to restore.
    }
};
