<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Spiritix\LadaCache\Tests\TestCase;

final class OctaneWarmingTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('octane.warm', ['view']);
    }

    public function testLadaServicesOutliveRequestsUnderOctane(): void
    {
        $this->assertSame(['view', 'lada.redis', 'lada.metrics', 'lada.cache', 'lada.handler'], config('octane.warm'));
    }
}
