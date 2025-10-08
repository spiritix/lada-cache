<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Redis\Connections\PredisConnection as RedisConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class CacheTest extends TestCase
{
    /** @var RedisConnection&MockObject */
    private RedisConnection $connection;
    private Redis $redis;
    private Encoder $encoder;

    protected function setUp(): void
    {
        parent::setUp();

        // Set predictable prefix for Redis proxy
        config(['lada-cache.prefix' => 'p:']);

        $this->connection = $this->getMockBuilder(RedisConnection::class)
            ->disableOriginalConstructor()
            ->addMethods(['exists', 'set', 'sadd', 'get', 'scan', 'del', 'unlink'])
            ->getMock();

        $this->redis = new Redis($this->connection);
        $this->encoder = new Encoder();
    }

    public function testHasReturnsTrueWhenKeyExistsAndFalseOtherwise(): void
    {
        $key = 'foo';
        $prefixed = 'p:foo';

        $this->assertSame($prefixed, $this->redis->prefix($key));

        $this->connection->expects($this->exactly(2))
            ->method('exists')
            ->with($prefixed)
            ->willReturnOnConsecutiveCalls(1, 0);

        $cache = new Cache($this->redis, $this->encoder, 0);

        $this->assertTrue($cache->has($key));
        $this->assertFalse($cache->has($key));
    }

    public function testSetStoresValueWithPrefixAndTagsWithExpiration(): void
    {
        $key = 'k';
        $prefixedKey = 'p:k';
        $tags = ['t1', 't2'];
        $prefixedTag1 = 'p:t1';
        $prefixedTag2 = 'p:t2';
        $value = ['a' => 1];
        $encoded = $this->encoder->encode($value);

        $this->assertSame($prefixedKey, $this->redis->prefix($key));
        $this->assertSame($prefixedTag1, $this->redis->prefix($tags[0]));
        $this->assertSame($prefixedTag2, $this->redis->prefix($tags[1]));

        $this->connection->expects($this->once())
            ->method('set')
            ->with($prefixedKey, $encoded, 'EX', 60);

        $seen = [];
        $this->connection->expects($this->exactly(2))
            ->method('sadd')
            ->willReturnCallback(function (string $tag, string $key) use (&$seen, $prefixedTag1, $prefixedTag2, $prefixedKey): void {
                $this->assertSame($prefixedKey, $key);
                $this->assertContains($tag, [$prefixedTag1, $prefixedTag2]);
                $seen[$tag] = true;
            });

        $cache = new Cache($this->redis, $this->encoder, 60);
        $cache->set($key, $tags, $value);
        $this->addToAssertionCount(1); // if no expectation failures, consider passed
    }

    public function testSetStoresValueWithoutExpirationWhenZero(): void
    {
        $key = 'k2';
        $prefixedKey = 'p:k2';
        $tags = ['t'];
        $prefixedTag = 'p:t';
        $value = 'string-value';
        $encoded = $this->encoder->encode($value);

        $this->assertSame($prefixedKey, $this->redis->prefix($key));
        $this->assertSame($prefixedTag, $this->redis->prefix($tags[0]));

        $this->connection->expects($this->once())
            ->method('set')
            ->with($prefixedKey, $encoded);

        $this->connection->expects($this->once())
            ->method('sadd')
            ->willReturnCallback(function (string $tag, string $key) use ($prefixedTag, $prefixedKey): void {
                $this->assertSame($prefixedTag, $tag);
                $this->assertSame($prefixedKey, $key);
            });

        $cache = new Cache($this->redis, $this->encoder, 0);
        $cache->set($key, $tags, $value);
        $this->addToAssertionCount(1);
    }

    public function testGetReturnsNullWhenKeyMissing(): void
    {
        $key = 'missing';
        $prefixed = 'p:missing';

        $this->assertSame($prefixed, $this->redis->prefix($key));

        $this->connection->expects($this->once())
            ->method('get')
            ->with($prefixed)
            ->willReturn(null);

        $cache = new Cache($this->redis, $this->encoder, 0);
        $this->assertNull($cache->get($key));
    }

    public function testGetDecodesEncodedPayload(): void
    {
        $key = 'payload';
        $prefixed = 'p:payload';
        $original = ['x' => 10, 'y' => [1, 2]];
        $encoded = $this->encoder->encode($original);

        $this->assertSame($prefixed, $this->redis->prefix($key));

        $this->connection->expects($this->once())
            ->method('get')
            ->with($prefixed)
            ->willReturn($encoded);

        $cache = new Cache($this->redis, $this->encoder, 0);
        $this->assertSame($original, $cache->get($key));
    }

    public function testFlushScansAndDeletesAllPrefixedKeys(): void
    {
        $pattern = 'p:*';

        $this->assertSame($pattern, $this->redis->prefix('*'));

        // First scan returns cursor '1' and two keys, second returns '0' and one key
        $this->connection->expects($this->exactly(2))
            ->method('scan')
            ->willReturnOnConsecutiveCalls(
                ['1', ['k1', 'k2']],
                ['0', ['k3']]
            );

        // Delete is called for each non-empty batch
        $delCall = 0;
        $this->connection->expects($this->exactly(2))
            ->method('del')
            ->willReturnCallback(function () use (&$delCall): void {
                $args = func_get_args();
                if ($delCall === 0) {
                    $this->assertSame(['k1', 'k2'], $args);
                } else {
                    $this->assertSame(['k3'], $args);
                }
                $delCall++;
            });

        $cache = new Cache($this->redis, $this->encoder, 0);
        $cache->flush();
        $this->addToAssertionCount(1);
    }
}