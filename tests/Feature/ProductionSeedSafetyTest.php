<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OperationalDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProductionSeedSafetyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('deployedSeeders')]
    public function test_demo_seeders_refuse_deployed_environments_before_creating_accounts(string $environment, string $seeder): void
    {
        $this->app['env'] = $environment;
        config(['app.env' => $environment]);

        $this->expectException(RuntimeException::class);
        try {
            Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
        } finally {
            $this->assertDatabaseCount('users', 0);
        }
    }

    public static function deployedSeeders(): array
    {
        return [
            'production default accounts' => ['production', DatabaseSeeder::class],
            'production operational accounts' => ['production', OperationalDataSeeder::class],
            'staging default accounts' => ['staging', DatabaseSeeder::class],
            'staging operational accounts' => ['staging', OperationalDataSeeder::class],
        ];
    }
}
