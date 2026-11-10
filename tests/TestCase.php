<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\LadaCacheServiceProvider;
use Spiritix\LadaCache\Support\DependentTables;

class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $defaultConnection = (string) config('database.default');
        $migrationParams = [
            '--database' => $defaultConnection,
            '--path' => realpath(__DIR__.'/Database/migrations'),
            '--realpath' => true,
            '--drop-views' => true,
        ];

        $this->artisan('migrate:fresh', $migrationParams);

        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            return sprintf('Spiritix\\LadaCache\\Tests\\Database\\Factories\\%sFactory', class_basename($modelName));
        });

        // Ensure a clean cache for every test: flush configured Redis connection
        $connection = (string) config('lada-cache.redis_connection', 'cache');
        Redis::connection($connection)->flushdb();

        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
    }

    /**
     * Puts the cache where production is after its first write: with the dependency map of the
     * default connection stored, so cached reads take the cascade tags they keep afterwards. Tests
     * asserting that a write keeps reads cached call it first; until some write loads the map,
     * reads take a fallback tag and miss once when it is loaded.
     */
    protected function loadDependencyMap(): void
    {
        $connection = DB::connection();
        $cache = app('lada.cache');
        $lookup = $cache->lookup('dependency-map', [], []);

        if ($lookup instanceof CacheMiss) {
            $cache->storeDependents($lookup->generation, $connection->getDatabaseName(), DependentTables::load($connection));
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            LadaCacheServiceProvider::class,
        ];
    }

    protected static function databaseDriver(): string
    {
        return (string) env('DB_CONNECTION', 'sqlite');
    }

    protected function requireDatabaseDriver(string ...$drivers): void
    {
        if (! in_array(self::databaseDriver(), $drivers, true)) {
            $this->markTestSkipped(sprintf('Needs %s, running on %s.', implode(' or ', $drivers), self::databaseDriver()));
        }
    }

    protected function getEnvironmentSetUp($app): void
    {
        $driver = self::databaseDriver();

        if ($driver === 'mysql') {
            $app['config']->set('database.default', 'mysql_testing');
            $app['config']->set('database.connections.mysql_testing', [
                'driver' => 'mysql',
                'host' => env('DB_HOST', 'mysql'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', 'secret'),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
            ]);
        } elseif ($driver === 'pgsql') {
            $app['config']->set('database.default', 'pgsql_testing');
            $app['config']->set('database.connections.pgsql_testing', [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', 'postgres'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'secret'),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'disable',
            ]);
        } else {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        // If the database Redis has a prefix, Lada fails to flush the cache
        $app['config']->set('database.redis.options.prefix', false);

        // Configure Redis host and port via environment to support Docker (service name),
        // the flake's published host port, and local runs.
        $redisHost = env('REDIS_HOST', '127.0.0.1');
        $redisPort = (int) env('REDIS_PORT', 6379);
        // Use Predis client by default to avoid requiring the native phpredis extension in CI/Docker.
        $app['config']->set('database.redis.client', env('REDIS_CLIENT', 'predis'));
        $app['config']->set('database.redis.default.host', $redisHost);
        $app['config']->set('database.redis.default.port', $redisPort);
        $app['config']->set('database.redis.cache.host', $redisHost);
        $app['config']->set('database.redis.cache.port', $redisPort);
    }
}
