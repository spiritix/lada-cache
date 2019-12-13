<?php

namespace Spiritix\LadaCache\Tests;

class CacheTest extends TestCase
{
    private $cache;

    public function setUp(): void
    {
        parent::setUp();

        $this->cache = app()->make('lada.cache');
    }

    public function testHas()
    {
        $this->assertFalse($this->cache->has('someKey'));

        $this->cache->set('someKey', ['tag'], 'data');

        $this->assertTrue($this->cache->has('someKey'));
    }

    public function testSet()
    {
        $this->cache->set('otherKey', ['tag'], 'data');

        $this->assertTrue($this->cache->has('otherKey'));
    }

    public function testGet()
    {
        $data = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        $this->cache->set('key', ['tag'], $data);

        $this->assertEquals($data, $this->cache->get('key'));
    }

    public function testFlush()
    {
        $this->cache->set('key', ['tag'], 'data');
        $this->cache->flush();

        $this->assertFalse($this->cache->has('key'));
    }
}
