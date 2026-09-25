<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class ControlCenterMigrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function migrateFreshUsing(): array
    {
        // Exercise this historical migration against its actual predecessor
        // schema, not today's dependent tables and irreversible data migrations.
        $paths = array_filter(
            app('migrator')->getMigrationFiles(database_path('migrations')),
            static fn (string $path): bool => basename($path) < '2026_09_14_064035_create_control_center_tables.php',
        );

        return ['--path' => array_values($paths), '--realpath' => true];
    }

    public function test_migration_removes_and_restores_retired_chat_pro_permission(): void
    {
        // The referral-code column and its creation hook did not exist yet.
        $user = User::withoutEvents(fn () => User::factory()->create([
            'permissions' => [...User::DEFAULT_PERMISSIONS, 'chat_ai_pro' => true],
        ]));

        $migration = require database_path('migrations/2026_09_14_064035_create_control_center_tables.php');
        $migration->up();

        $this->assertArrayNotHasKey('chat_ai_pro', $user->fresh()->permissions);
        $this->assertArrayNotHasKey('__retired_chat_ai_pro', $user->fresh()->getPermissions());

        $migration->down();
        $this->assertTrue($user->fresh()->permissions['chat_ai_pro']);
        $this->assertArrayNotHasKey('__retired_chat_ai_pro', $user->fresh()->permissions);
    }
}
