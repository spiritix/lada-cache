<?php

namespace Spiritix\LadaCache\Tests\Console;

use Spiritix\LadaCache\Tests\TestCase;

class FlushCommandTest extends TestCase
{
    public function testHandle()
    {
        $manager = app()->make('LadaCache');
        $redis = $manager->getRedis();

        $redis->set($redis->prefix('test'), 'test');
        $manager->flush();

        $keys = $redis->keys($redis->prefix('*'));

        $this->assertEmpty($keys);
    }
}