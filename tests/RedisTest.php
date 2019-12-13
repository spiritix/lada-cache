<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Redis;

class RedisTest extends TestCase
{
    private $redis;

    public function setUp(): void
    {
        parent::setUp();

        $this->redis = new Redis();
        $this->redis->prefix = 'prefix:';
    }

    public function testPrefix()
    {
        $this->assertEquals('prefix:value', $this->redis->prefix('value'));
    }

    public function testCall()
    {
        $this->expectException('Error');

        $this->redis->doesNotExistInPredis();
    }
}
