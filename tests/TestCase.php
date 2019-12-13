<?php

namespace Spiritix\LadaCache\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Application;
use Illuminate\Database\DatabaseServiceProvider;
use Spiritix\LadaCache\LadaCacheServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    private $useArtisan;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);

        $this->useArtisan = version_compare('5.4', Application::VERSION, '>');
    }

    public function setUp(): void
    {
        parent::setUp();

        $migrationParams = [
            '--database' => 'testing',
            '--path' => realpath(__DIR__.'/../database/migrations'),
            '--realpath' => true,
        ];

        if ($this->useArtisan) {
            $this->artisan('migrate', $migrationParams);
        } else {
            $this->loadMigrationsFrom($migrationParams);
        }
        
        $this->withFactories(realpath(__DIR__.'/../database/factories'));
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
    }
}
