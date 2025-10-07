<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spiritix\LadaCache\LadaCacheServiceProvider;

class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migrationParams = [
            '--database' => 'testing',
            '--path' => realpath(__DIR__.'/Database/migrations'),
            '--realpath' => true,
        ];

        $this->artisan('migrate', $migrationParams);

        Factory::guessFactoryNamesUsing(static function (string $modelName): string {
            return sprintf('Spiritix\\LadaCache\\Tests\\Database\\Factories\\%sFactory', class_basename($modelName));
        });

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LadaCacheServiceProvider::class
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // If the database Redis has a prefix, Lada fails to flush the cache
        $app['config']->set('database.redis.options.prefix', false);

        // Set Redis host name for Docker
        // $app['config']->set('database.redis.default.host', 'redis');
    }
}
