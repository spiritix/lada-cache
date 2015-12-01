<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\LadaCacheServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected function getPackageProviders($app)
    {
        return [LadaCacheServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('lada-cache.active', true);
        $app['config']->set('lada-cache.prefix', 'lada:');
        $app['config']->set('lada-cache.consider-rows', true);
    }
}