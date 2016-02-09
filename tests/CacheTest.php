<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Reflector\AbstractReflector;
use Spiritix\LadaCache\Cache;

class CacheTest extends TestCase
{
    private $cache;

    public function setUp()
    {
        parent::setUp();

        $manager = app()->make('LadaCache');

        $reflector = $this->getMockForAbstractClass(
            AbstractReflector::class,
            [], '', true, true, true,
            ['getHash', 'getTags']
        );

        $reflector->method('getHash')
            ->will($this->returnValue(md5('hash')));

        $reflector->method('getTags')
            ->will($this->returnValue(['tag1', 'tag2']));

        $this->cache = $manager->resolve($reflector);
    }

    public function testInstanceOf()
    {
        $this->assertInstanceOf(Cache::class, $this->cache);
    }

    public function testHas()
    {
        $this->assertFalse($this->cache->has());

        $this->cache->set(['data']);

        $this->assertTrue($this->cache->has());
    }

    public function testSet()
    {
        $this->cache->set(['data']);

        $this->assertTrue($this->cache->has());
    }

    public function testGet()
    {
        $data = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        $this->cache->set($data);

        $this->assertEquals($data, $this->cache->get());
    }

    public function testInvalidate()
    {
        $this->cache->invalidate();

        $this->assertFalse($this->cache->has());
    }
}