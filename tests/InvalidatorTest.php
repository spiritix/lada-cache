<?php

namespace Spiritix\LadaCache\Tests;

class InvalidatorTest extends TestCase
{
    private $cache;

    private $invalidator;

    public function setUp(): void
    {
        parent::setUp();

        $this->cache = app()->make('lada.cache');
        $this->invalidator = app()->make('lada.invalidator');
    }

    public function testInvalidate()
    {
        $this->cache->set('key', ['tag1', 'tag4'], 'data');

        $this->invalidator->invalidate(['tag1', 'tag4']);

        $this->assertFalse($this->cache->has('key'));

        $this->assertFalse($this->cache->has('tag1'));
        $this->assertFalse($this->cache->has('tag4'));
    }

    public function testInvalidateMultiTags()
    {
        $this->cache->set('key1', ['tag1'], 'data');
        $this->cache->set('key2', ['tag2'], 'data');

        $this->invalidator->invalidate(['tag1', 'tag2']);

        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));

        $this->assertFalse($this->cache->has('tag1'));
        $this->assertFalse($this->cache->has('tag2'));
    }
}
