<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Spiritix\LadaCache\Tests\TestCase;

final class OctaneWarmingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('octane.warm', ['view', 'lada.cache']);
    }

    public function testLadaServicesAreWarmedUnderOctane(): void
    {
        $this->assertSame(['view', 'lada.cache', 'lada.redis', 'lada.invalidator', 'lada.handler'], config('octane.warm'));
    }
}
