<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        User::firstOrCreate(
            ['email' => 'admin@ultrai.id'],
            [
                'name' => 'Admin UltrAI',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        // Create demo member
        User::firstOrCreate(
            ['email' => 'member@ultrai.id'],
            [
                'name' => 'Member Demo',
                'password' => Hash::make('password'),
                'role' => 'member',
                'is_active' => true,
            ]
        );
    }
}
