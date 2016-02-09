<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Redis;

class RedisTest extends TestCase
{
    private $redis;

    public function setUp()
    {
        parent::setUp();

        $this->redis = new Redis(config('lada-cache'));
    }

    public function testPrefix()
    {
        $expected = config('lada-cache.prefix') . 'value';

        $this->assertEquals($expected, $this->redis->prefix('value'));
    }

    public function testCall()
    {
        $this->setExpectedException('Predis\ClientException');

        $this->redis->doesNotExistInPredis();
    }
}