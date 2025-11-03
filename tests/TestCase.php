<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Redis;
use Spiritix\LadaCache\LadaCacheServiceProvider;

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
        ];

        $this->artisan('migrate:fresh', $migrationParams);

        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            return sprintf('Spiritix\\LadaCache\\Tests\\Database\\Factories\\%sFactory', class_basename($modelName));
        });

        // Ensure a clean cache for every test: flush configured Redis connection
        $connection = (string) config('lada-cache.redis_connection', 'cache');
        Redis::connection($connection)->flushdb();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LadaCacheServiceProvider::class
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $driver = env('DB_CONNECTION', 'sqlite');

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
        } else {
            $app['config']->set('database.default', 'testing');
            $app['config']->set('database.connections.testing', [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ]);
        }

        // If the database Redis has a prefix, Lada fails to flush the cache
        $app['config']->set('database.redis.options.prefix', false);

        // Configure Redis host via environment to support Docker (service name) and local runs.
        // In Docker, pass REDIS_HOST=redis; locally, default to 127.0.0.1.
        $redisHost = env('REDIS_HOST', '127.0.0.1');
        // Use Predis client to avoid requiring the native phpredis extension in CI/Docker.
        $app['config']->set('database.redis.client', 'predis');
        $app['config']->set('database.redis.default.host', $redisHost);
        $app['config']->set('database.redis.cache.host', $redisHost);
    }
}
