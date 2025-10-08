<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\Redis as RedisFacade;
use Illuminate\Redis\Connections\Connection;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class RedisTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.prefix' => 'lada:']);

        $this->redis = new Redis();
    }

    public function testPrefixConcatenatesConfiguredPrefixAndKey(): void
    {
        $this->assertSame('lada:users', $this->redis->prefix('users'));
        $this->assertSame('lada:*', $this->redis->prefix('*'));
    }

    public function testGetConnectionReturnsInjectedConnection(): void
    {
        $expected = RedisFacade::connection((string) config('lada-cache.redis_connection', 'cache'));
        $this->assertSame($expected, $this->redis->getConnection());
        $this->assertInstanceOf(Connection::class, $this->redis->getConnection());
    }

    public function testDynamicCallsAreForwardedToUnderlyingConnection(): void
    {
        // Forward a typical Redis command via proxy and assert round-trip
        $key = 'lada:test:key';
        $this->redis->set($key, 'value');
        $this->assertSame('value', $this->redis->get($key));
    }
}
