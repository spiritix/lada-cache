<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Redis\Connections\PredisConnection as RedisConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class RedisTest extends TestCase
{
    /** @var RedisConnection&MockObject */
    private RedisConnection $connection;
    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.prefix' => 'lada:']);

        $this->connection = $this->getMockBuilder(RedisConnection::class)
            ->disableOriginalConstructor()
            ->addMethods(['set', 'get'])
            ->getMock();

        $this->redis = new Redis($this->connection);
    }

    public function testPrefixConcatenatesConfiguredPrefixAndKey(): void
    {
        $this->assertSame('lada:users', $this->redis->prefix('users'));
        $this->assertSame('lada:*', $this->redis->prefix('*'));
    }

    public function testGetConnectionReturnsInjectedConnection(): void
    {
        $this->assertSame($this->connection, $this->redis->getConnection());
    }

    public function testDynamicCallsAreForwardedToUnderlyingConnection(): void
    {
        // Forward a typical Redis command
        $this->connection->expects($this->once())
            ->method('set')
            ->with('key', 'value')
            ->willReturn('OK');

        $result = $this->redis->set('key', 'value');
        $this->assertSame('OK', $result);

        // And another dynamic call
        $this->connection->expects($this->once())
            ->method('get')
            ->with('key')
            ->willReturn('value');

        $this->assertSame('value', $this->redis->get('key'));
    }
}
