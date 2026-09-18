<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\PostgresDatabaseGuard;

class PostgresDatabaseGuardTest extends TestCase
{
    #[DataProvider('unsafeTargets')]
    public function test_rejects_targets_that_can_escape_the_test_database(string $environment, string $connection, array $changes): void
    {
        $target = [
            'driver' => 'pgsql',
            'url' => '',
            'host' => '127.0.0.1',
            'port' => '2209',
            'database' => 'ultrai_pg_test',
            'username' => 'ultrai_pg_test',
            'password' => 'test-only-password',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'timezone' => 'UTC',
            'sslmode' => 'prefer',
            'options' => [],
        ];
        PostgresDatabaseGuard::assertTarget('testing', 'pgsql', $target, 'test-only-password');

        $this->expectException(RuntimeException::class);

        PostgresDatabaseGuard::assertTarget($environment, $connection, array_replace($target, $changes), 'test-only-password');
    }

    public static function unsafeTargets(): array
    {
        return [
            'inherited production environment' => ['production', 'pgsql', []],
            'SQLite QA connection' => ['testing', 'sqlite', []],
            'non-PostgreSQL driver' => ['testing', 'pgsql', ['driver' => 'sqlite']],
            'primary PostgreSQL database' => ['testing', 'pgsql', ['database' => 'ultrai_db']],
            'non-loopback host' => ['testing', 'pgsql', ['host' => '192.0.2.1']],
            'different local port' => ['testing', 'pgsql', ['port' => '5432']],
            'primary PostgreSQL role' => ['testing', 'pgsql', ['username' => 'ultrai_local']],
            'inherited database password' => ['testing', 'pgsql', ['password' => 'unrelated-password']],
            'URL overrides approved fields' => ['testing', 'pgsql', ['url' => 'pgsql://127.0.0.1/ultrai_db']],
            'read connection redirects queries' => ['testing', 'pgsql', ['read' => ['database' => 'ultrai_db']]],
            'write connection redirects migrations' => ['testing', 'pgsql', ['write' => ['database' => 'ultrai_db']]],
            'connector database override' => ['testing', 'pgsql', ['connect_via_database' => 'ultrai_db']],
            'connector port override' => ['testing', 'pgsql', ['connect_via_port' => '5432']],
            'different schema' => ['testing', 'pgsql', ['search_path' => 'other']],
            'non-UTC session' => ['testing', 'pgsql', ['timezone' => 'Asia/Jakarta']],
        ];
    }

    #[DataProvider('unsafeIdentities')]
    public function test_rejects_live_identity_mismatches_before_migrations(array $changes): void
    {
        $identity = [
            'database_name' => 'ultrai_pg_test',
            'database_user' => 'ultrai_pg_test',
            'session_user' => 'ultrai_pg_test',
            'server_version_num' => '180003',
            'search_path' => '"public"',
            'timezone' => 'UTC',
        ];
        PostgresDatabaseGuard::assertIdentity($identity);

        $this->expectException(RuntimeException::class);

        PostgresDatabaseGuard::assertIdentity(array_replace($identity, $changes));
    }

    public static function unsafeIdentities(): array
    {
        return [
            'connected to primary database' => [['database_name' => 'ultrai_db']],
            'connected as primary role' => [['database_user' => 'ultrai_local']],
            'primary role switched to test role' => [['session_user' => 'ultrai_local']],
            'different PostgreSQL major version' => [['server_version_num' => '170005']],
            'malformed server version' => [['server_version_num' => '180003-invalid']],
            'search path includes another schema' => [['search_path' => 'public, other']],
            'session timezone was not applied' => [['timezone' => 'Asia/Jakarta']],
        ];
    }

    public function test_missing_test_password_never_falls_back_to_the_application_password(): void
    {
        $originalTestPassword = getenv('ULTRAI_PG_TEST_PASSWORD');
        $originalDatabasePassword = getenv('DB_PASSWORD');

        try {
            putenv('ULTRAI_PG_TEST_PASSWORD');
            putenv('DB_PASSWORD=unrelated-test-fixture-password');
            $this->expectException(RuntimeException::class);

            PostgresDatabaseGuard::createApplication();
        } finally {
            putenv($originalTestPassword === false ? 'ULTRAI_PG_TEST_PASSWORD' : 'ULTRAI_PG_TEST_PASSWORD='.$originalTestPassword);
            putenv($originalDatabasePassword === false ? 'DB_PASSWORD' : 'DB_PASSWORD='.$originalDatabasePassword);
        }
    }
}
