<?php

namespace Spiritix\LadaCache\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\LadaCacheServiceProvider;
use Spiritix\LadaCache\Tests\Database\factories\CarFactory;

class TestCase extends \Orchestra\Testbench\TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $migrationParams = [
            '--database' => 'testing',
            '--path' => realpath(__DIR__.'/../database/migrations'),
            '--realpath' => true,
        ];

        $this->artisan('migrate', $migrationParams);

        Factory::guessFactoryNamesUsing(static function (string $modelName) {
            return sprintf("Spiritix\\LadaCache\\Tests\\Database\\Factories\\%sFactory", class_basename($modelName));
        });

        DB::beginTransaction();
    }

    public function tearDown(): void
    {
        DB::rollback();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            LadaCacheServiceProvider::class
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('lada-cache.active', true);
        $app['config']->set('lada-cache.prefix', 'lada:');
        $app['config']->set('lada-cache.consider-rows', true);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        //If the database redis has a prefix, Lada fails to flush the cache
        $app['config']->set('database.redis.options.prefix', false);
    }
}
