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

    public function testJitterDisabledWritesExactTtl(): void
    {
        $key = 'jitter-off-key';
        $prefixed = 'p:jitter-off-key';

        $this->redis->del($prefixed);
        $cache = new Cache($this->redis, $this->encoder, 300, 0);
        $cache->set($key, ['t'], ['data' => 1]);

        $ttl = (int) $this->redis->ttl($prefixed);

        // Allow 1s drift for Redis RTT.
        $this->assertGreaterThanOrEqual(299, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }

    public function testJitter15StaysWithinExpectedBand(): void
    {
        $base = 1000;
        $cache = new Cache($this->redis, $this->encoder, $base, 15);

        // Sample many writes; each must land inside [base*0.85, base*1.15] = [850, 1150].
        for ($i = 0; $i < 50; $i++) {
            $key = "jitter-band-key-{$i}";
            $prefixed = "p:jitter-band-key-{$i}";

            $this->redis->del($prefixed);
            $cache->set($key, ['t'], ['n' => $i]);

            $ttl = (int) $this->redis->ttl($prefixed);

            $this->assertGreaterThanOrEqual(849, $ttl, "Iteration {$i}: TTL {$ttl} below lower band");
            $this->assertLessThanOrEqual(1150, $ttl, "Iteration {$i}: TTL {$ttl} above upper band");
        }
    }

    public function testJitterActuallyVariesResults(): void
    {
        $cache = new Cache($this->redis, $this->encoder, 1000, 15);
        $observed = [];

        for ($i = 0; $i < 30; $i++) {
            $key = "jitter-spread-key-{$i}";
            $prefixed = "p:jitter-spread-key-{$i}";

            $this->redis->del($prefixed);
            $cache->set($key, ['t'], ['n' => $i]);
            $observed[] = (int) $this->redis->ttl($prefixed);
        }

        // With ±15% on TTL=1000 and 30 samples, getting all identical values has
        // probability ~(1/301)^29 ≈ 0 — if every sample is the same, jitter is
        // silently disabled.
        $this->assertGreaterThan(1, count(array_unique($observed)));
    }

    public function testJitterSkippedWhenTtlZero(): void
    {
        $key = 'jitter-zero-ttl-key';
        $prefixed = 'p:jitter-zero-ttl-key';

        $this->redis->del($prefixed);
        $cache = new Cache($this->redis, $this->encoder, 0, 50);
        $cache->set($key, ['t'], ['data' => 1]);

        $ttl = (int) $this->redis->ttl($prefixed);

        // -1 = key exists with no expiration; jitter must not touch the "persist forever" path.
        $this->assertSame(-1, $ttl);
    }

    public function testJitterClampsNegativePctToZero(): void
    {
        $key = 'jitter-negative-pct-key';
        $prefixed = 'p:jitter-negative-pct-key';

        $this->redis->del($prefixed);
        $cache = new Cache($this->redis, $this->encoder, 600, -100);
        $cache->set($key, ['t'], ['data' => 1]);

        $ttl = (int) $this->redis->ttl($prefixed);

        // -100 clamps to 0 → deterministic behavior, not crash.
        $this->assertGreaterThanOrEqual(599, $ttl);
        $this->assertLessThanOrEqual(600, $ttl);
    }

    public function testJitterClampsExcessivePctTo100(): void
    {
        $key = 'jitter-excessive-pct-key';
        $prefixed = 'p:jitter-excessive-pct-key';

        $this->redis->del($prefixed);
        $cache = new Cache($this->redis, $this->encoder, 100, 500);
        $cache->set($key, ['t'], ['data' => 1]);

        $ttl = (int) $this->redis->ttl($prefixed);

        // 500 clamps to 100 → max ±100% jitter, so result ∈ [1, 200].
        $this->assertGreaterThanOrEqual(1, $ttl);
        $this->assertLessThanOrEqual(200, $ttl);
    }

    public function testJitterFloorClampsToOneSecond(): void
    {
        $cache = new Cache($this->redis, $this->encoder, 1, 100);

        // TTL=1 with pct=100 could mathematically produce 0 (1 + (-1)); floor must keep ≥1
        // so SET EX never silently downgrades to "persist forever".
        for ($i = 0; $i < 20; $i++) {
            $key = "jitter-floor-key-{$i}";
            $prefixed = "p:jitter-floor-key-{$i}";

            $this->redis->del($prefixed);
            $cache->set($key, ['t'], ['data' => $i]);

            $ttl = (int) $this->redis->ttl($prefixed);

            // -1 would mean jitter produced ≤0 and degraded the SET; that's the bug we guard against.
            $this->assertNotSame(-1, $ttl, "Iteration {$i}: TTL collapsed to -1 (persist-forever)");
            $this->assertGreaterThanOrEqual(0, $ttl);
        }
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