<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use PDO;
use RuntimeException;
use SensitiveParameter;
use Throwable;

final class PostgresDatabaseGuard
{
    private const CONNECTION = [
        'driver' => 'pgsql',
        'url' => '',
        'host' => '127.0.0.1',
        'port' => '2209',
        'database' => 'ultrai_pg_test',
        'username' => 'ultrai_pg_test',
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'timezone' => 'UTC',
        'sslmode' => 'prefer',
        'options' => [],
    ];

    public static function createApplication(): Application
    {
        // Read the process environment before Laravel can load any .env file.
        $password = getenv('ULTRAI_PG_TEST_PASSWORD');
        if (! is_string($password) || trim($password) === '') {
            throw new RuntimeException('PostgreSQL tests require an explicit ULTRAI_PG_TEST_PASSWORD process environment variable.');
        }

        foreach ([
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => self::CONNECTION['host'],
            'DB_PORT' => self::CONNECTION['port'],
            'DB_DATABASE' => self::CONNECTION['database'],
            'DB_USERNAME' => self::CONNECTION['username'],
            'DB_PASSWORD' => $password,
            'DB_URL' => '',
            'DB_QUEUE_CONNECTION' => 'pgsql',
            'APP_CONFIG_CACHE' => 'storage/framework/testing/phpunit-postgres-config.php',
        ] as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
            putenv($name.'='.$value);
        }

        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app) use ($password): void {
            // Replace the entire connection map, including cached URLs/read-write overrides,
            // before any provider boots. No primary database connection is retained.
            $app->make('config')->set([
                'app.env' => 'testing',
                'app.debug' => false,
                'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
                'app.timezone' => 'UTC',
                'app.maintenance.driver' => 'cache',
                'app.maintenance.store' => 'array',
                'database.default' => 'pgsql',
                'database.connections' => ['pgsql' => [...self::CONNECTION, 'password' => $password]],
                'cache.default' => 'array',
                'session.driver' => 'array',
                'queue.default' => 'sync',
                'queue.connections.database.connection' => 'pgsql',
                'queue.connections.media.connection' => 'pgsql',
                'queue.batching.database' => 'pgsql',
                'queue.failed.database' => 'pgsql',
                'mail.default' => 'array',
                'broadcasting.default' => 'null',
                'hashing.bcrypt.rounds' => 4,
                'services.ai_proxy.url' => 'http://127.0.0.1:1',
                'services.ai_proxy.key' => '',
                'pulse.enabled' => false,
                'telescope.enabled' => false,
                'nightwatch.enabled' => false,
            ]);
            $app->instance('env', 'testing');
            date_default_timezone_set('UTC');
        });
        $app->beforeBootstrapping(BootProviders::class, static function (Application $app) use ($password): void {
            self::assertDatabase($app, $password);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public static function assertTarget(string $environment, string $connection, #[SensitiveParameter] array $configuration, #[SensitiveParameter] string $password): void
    {
        if ($environment !== 'testing' || $connection !== 'pgsql' || trim($password) === '') {
            throw new RuntimeException('PostgreSQL tests require the explicit isolated testing connection.');
        }

        $expected = [...self::CONNECTION, 'password' => $password];
        if (array_diff(array_keys($configuration), [...array_keys($expected), 'name']) !== []) {
            throw new RuntimeException('PostgreSQL tests refuse additional database connection overrides.');
        }

        foreach ($expected as $name => $value) {
            $actual = $configuration[$name] ?? ($name === 'url' ? '' : null);
            if ($name === 'port' && is_int($actual)) {
                $actual = (string) $actual;
            }
            if ($actual !== $value) {
                throw new RuntimeException('PostgreSQL tests require pgsql on 127.0.0.1:2209, database and role ultrai_pg_test, public schema, and UTC.');
            }
        }
    }

    public static function assertIdentity(array $identity): void
    {
        $version = (string) ($identity['server_version_num'] ?? '');
        if (($identity['database_name'] ?? null) !== 'ultrai_pg_test'
            || ($identity['database_user'] ?? null) !== 'ultrai_pg_test'
            || ($identity['session_user'] ?? null) !== 'ultrai_pg_test'
            || ! ctype_digit($version) || (int) $version < 180000 || (int) $version >= 190000
            || ! in_array($identity['search_path'] ?? null, ['public', '"public"'], true)
            || ($identity['timezone'] ?? null) !== 'UTC') {
            throw new RuntimeException('PostgreSQL tests refuse an unverified database, role, PostgreSQL 18 server, schema, or timezone.');
        }
    }

    private static function assertDatabase(Application $app, #[SensitiveParameter] string $password): void
    {
        $config = $app->make('config');
        self::assertTarget($app->environment(), $config->get('database.default'), $config->get('database.connections.pgsql', []), $password);

        $connection = $app->make('db')->connection('pgsql');
        self::assertTarget($app->environment(), $config->get('database.default'), $connection->getConfig(), $password);

        try {
            $pdo = $connection->getPdo();
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
                throw new RuntimeException('The test connection is not PostgreSQL.');
            }
            $identity = $pdo->query("SELECT current_database() AS database_name, current_user AS database_user,
                session_user AS session_user, current_setting('server_version_num') AS server_version_num,
                current_setting('search_path') AS search_path, current_setting('TimeZone') AS timezone")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            // Never attach the underlying PDO exception, connection values, or credentials.
            throw new RuntimeException('Cannot verify the isolated PostgreSQL test connection; check the test server and ULTRAI_PG_TEST_PASSWORD.');
        }

        self::assertIdentity(is_array($identity) ? $identity : []);
    }
}
