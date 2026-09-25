<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Demo accounts may only be seeded in local or testing environments.');
        }

        // Create admin user
        User::firstOrCreate(
            ['email' => 'admin@xsuper.dev'],
            [
                'name' => 'Admin XSuper.ai',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        // Create demo member
        User::firstOrCreate(
            ['email' => 'member@xsuper.dev'],
            [
                'name' => 'Member Demo',
                'password' => Hash::make('password'),
                'role' => 'member',
                'is_active' => true,
            ]
        );
    }
}
