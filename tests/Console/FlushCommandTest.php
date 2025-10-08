<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Spiritix\LadaCache\Tests\TestCase;

class FlushCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['lada-cache.enable_debugbar' => false]);
    }

    public function testFlushIsNoOpWhenDisabled(): void
    {
        config(['lada-cache.active' => false]);

        $this->artisan('lada-cache:flush')
            ->expectsOutput('Lada Cache is disabled. Nothing to flush.')
            ->assertExitCode(0);
    }

    public function testFlushCallsCacheAndSucceeds(): void
    {
        config(['lada-cache.active' => true]);

        $this->artisan('lada-cache:flush')
            ->expectsOutput('Lada Cache has been flushed successfully.')
            ->assertExitCode(0);
    }
}

