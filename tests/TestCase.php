<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $profile = $_SERVER['ULTRAI_DATABASE_TEST_PROFILE'] ?? $_ENV['ULTRAI_DATABASE_TEST_PROFILE'] ?? getenv('ULTRAI_DATABASE_TEST_PROFILE');
        if ($profile === 'postgres') {
            $this->traitsUsedByTest = class_uses_recursive(static::class);

            return PostgresDatabaseGuard::createApplication();
        }
        if ($profile !== false && $profile !== null && $profile !== '') {
            throw new \RuntimeException('Unknown database test profile.');
        }

        $app = parent::createApplication();
        if (! $app->environment('testing') || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Feature tests require the isolated in-memory SQLite database.');
        }

        return $app;
    }
}
