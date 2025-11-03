<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class DisabledModeModelQueryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        // Set config to false BEFORE providers are registered
        $app['config']->set('lada-cache.active', false);
        
        return parent::getPackageProviders($app);
    }

    public function testModelQueriesWorkWhenDisabled(): void
    {
        // Verify that lada.handler is NOT bound when disabled
        $this->assertFalse($this->app->bound('lada.handler'), 'lada.handler should not be bound when disabled');
        
        // Models using LadaCacheTrait should work without exceptions when disabled
        $result = Car::all();
        
        $this->assertIsIterable($result);
    }
}
