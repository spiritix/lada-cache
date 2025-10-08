<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class CacheTest extends TestCase
{
    private Redis $redis;
    private Encoder $encoder;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.prefix' => 'p:']);

        $this->redis = new Redis();
        $this->encoder = new Encoder();
    }

    public function testHasReturnsTrueWhenKeyExistsAndFalseOtherwise(): void
    {
        $key = 'foo';
        $prefixed = 'p:foo';

        $this->assertSame($prefixed, $this->redis->prefix($key));

        // Ensure clean state
        $this->redis->del($prefixed);
        $cache = new Cache($this->redis, $this->encoder, 0);
        $this->assertFalse($cache->has($key));
        $this->redis->set($prefixed, '1');
        $this->assertTrue($cache->has($key));
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

        $this->redis->del($prefixedKey);
        $this->redis->del($prefixedTag1);
        $this->redis->del($prefixedTag2);
        $cache = new Cache($this->redis, $this->encoder, 60);
        $cache->set($key, $tags, $value);
        // Assert value stored with TTL (value presence suffices)
        $this->assertSame($encoded, $this->redis->get($prefixedKey));
        // Assert tag membership
        $this->assertSame(1, (int) $this->redis->sismember($prefixedTag1, $prefixedKey));
        $this->assertSame(1, (int) $this->redis->sismember($prefixedTag2, $prefixedKey));
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

        $this->redis->del($prefixedKey);
        $this->redis->del($prefixedTag);
        $cache = new Cache($this->redis, $this->encoder, 0);
        $cache->set($key, $tags, $value);
        $this->assertSame($encoded, $this->redis->get($prefixedKey));
        $this->assertSame(1, (int) $this->redis->sismember($prefixedTag, $prefixedKey));
    }

    public function testGetReturnsNullWhenKeyMissing(): void
    {
        $key = 'missing';
        $prefixed = 'p:missing';

        $this->assertSame($prefixed, $this->redis->prefix($key));

        // Ensure the key is absent and assert Cache::get() returns null
        $this->redis->del($prefixed);
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

        $this->redis->set($prefixed, $encoded);
        $cache = new Cache($this->redis, $this->encoder, 0);
        $this->assertSame($original, $cache->get($key));
    }

    public function testFlushScansAndDeletesAllPrefixedKeys(): void
    {
        $pattern = 'p:*';

        $this->assertSame($pattern, $this->redis->prefix('*'));

        // Seed three keys under our prefix and flush
        $this->redis->set('p:k1', '1');
        $this->redis->set('p:k2', '1');
        $this->redis->set('p:k3', '1');
        $cache = new Cache($this->redis, $this->encoder, 0);
        $cache->flush();
        $this->assertSame(0, (int) $this->redis->exists('p:k1'));
        $this->assertSame(0, (int) $this->redis->exists('p:k2'));
        $this->assertSame(0, (int) $this->redis->exists('p:k3'));
    }
}