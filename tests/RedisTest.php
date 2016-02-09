<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Redis;

class RedisTest extends TestCase
{
    private $redis;

    public function setUp()
    {
        parent::setUp();

        $this->redis = new Redis('prefix:');
    }

    public function testPrefix()
    {
        $this->assertEquals('prefix:value', $this->redis->prefix('value'));
    }

    public function testCall()
    {
        $this->setExpectedException('Predis\ClientException');

        $this->redis->doesNotExistInPredis();
    }
}