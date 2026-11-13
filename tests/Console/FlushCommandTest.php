<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Spiritix\LadaCache\Redis;
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

    public function testFlushReportsDeletedKeys(): void
    {
        $redis = new Redis;
        $redis->set($redis->prefix('k1'), '1');
        $redis->set($redis->prefix('k2'), '1');

        $this->artisan('lada-cache:flush')
            ->expectsOutputToContain('Deleted 2 keys in')
            ->assertExitCode(0);

        $this->assertSame(0, (int) $redis->exists($redis->prefix('k1')));
    }

    public function testFlushFailsWhenRedisIsUnreachable(): void
    {
        $this->useUnreachableRedis();

        $this->artisan('lada-cache:flush')
            ->expectsOutputToContain('Failed to flush Lada Cache: Connection refused')
            ->doesntExpectOutput('Lada Cache has been flushed successfully.')
            ->assertExitCode(1);
    }
}
