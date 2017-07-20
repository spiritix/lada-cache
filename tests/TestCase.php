<?php

namespace Spiritix\LadaCache\Tests;

use Illuminate\Support\Facades\DB;
use Laracasts\TestDummy\Factory;
use Spiritix\LadaCache\LadaCacheServiceProvider;
use Orchestra\Database\ConsoleServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected $factory;

    public function setUp()
    {
        parent::setUp();

        $this->loadMigrationsFrom([
            '--database' => 'testing',
            '--realpath' => realpath(__DIR__.'/../database/migrations'),
        ]);

        $this->factory = new Factory(__DIR__ . '/../database/factories');

        DB::beginTransaction();
    }

    public function tearDown()
    {
        DB::rollback();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            LadaCacheServiceProvider::class,
            ConsoleServiceProvider::class
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
    }
}