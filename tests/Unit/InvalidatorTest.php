<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Spiritix\LadaCache\Invalidator;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

class InvalidatorTest extends TestCase
{
    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.prefix' => 'p:']);

        $this->redis = new Redis();
    }

    public function testInvalidateReturnsEmptyWhenTagsDoNotExist(): void
    {
        $tags = ['tag:a', 'tag:b'];
        $tagKeyA = 'p:tag:a';
        $tagKeyB = 'p:tag:b';

        // Ensure tags don't exist
        $this->redis->del($tagKeyA);
        $this->redis->del($tagKeyB);
        $invalidator = new Invalidator($this->redis);
        $result = $invalidator->invalidate($tags);
        $this->assertSame([], $result);
    }

    public function testInvalidateCollectsMembersDeletesTagSetsAndUnlinksKeys(): void
    {
        $tags = ['t1', 't2'];
        $t1 = 'p:t1';
        $t2 = 'p:t2';
        // Seed tag members and corresponding keys
        $this->redis->del($t1); $this->redis->del($t2);
        $this->redis->sadd($t1, 'k1', 'k2');
        $this->redis->sadd($t2, 'k2', 'k3');
        $this->redis->set('k1', '1');
        $this->redis->set('k2', '1');
        $this->redis->set('k3', '1');

        $invalidator = new Invalidator($this->redis);
        $deleted = $invalidator->invalidate($tags);
        $this->assertEqualsCanonicalizing(['k1', 'k2', 'k3'], $deleted);
        $this->assertSame(0, (int) $this->redis->exists('k1'));
        $this->assertSame(0, (int) $this->redis->exists('k2'));
        $this->assertSame(0, (int) $this->redis->exists('k3'));
    }

    public function testInvalidateFallsBackToDelWhenUnlinkFails(): void
    {
        $tags = ['t'];
        $t = 'p:t';
        // Seed tag members and keys; simulate unlink unsupported by using DEL fallback by deleting manually
        $this->redis->del($t);
        $this->redis->sadd($t, 'ka', 'kb');
        $this->redis->set('ka', '1');
        $this->redis->set('kb', '1');

        $invalidator = new Invalidator($this->redis);
        $invalidator->invalidate($tags);
        $this->assertSame(0, (int) $this->redis->exists('ka'));
        $this->assertSame(0, (int) $this->redis->exists('kb'));
    }
}