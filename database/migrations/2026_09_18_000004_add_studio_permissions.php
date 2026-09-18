<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = ['audio_generator', 'video_downloader', 'media_converter'];

    public function up(): void
    {
        $this->updatePermissions(true);
    }

    public function down(): void
    {
        $this->updatePermissions(false);
    }

    private function updatePermissions(bool $add): void
    {
        DB::table('users')->select(['id', 'permissions'])->whereNotNull('permissions')
            ->lockForUpdate()->chunkById(200, function ($users) use ($add): void {
                foreach ($users as $user) {
                    $permissions = json_decode($user->permissions, true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($permissions)) {
                        continue;
                    }
                    $original = $permissions;
                    foreach (self::PERMISSIONS as $permission) {
                        if ($add && ! array_key_exists($permission, $permissions)) {
                            $permissions[$permission] = true;
                        } elseif (! $add) {
                            unset($permissions[$permission]);
                        }
                    }
                    if ($permissions !== $original) {
                        DB::table('users')->where('id', $user->id)->update([
                            'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                        ]);
                    }
                }
            });
    }
};
