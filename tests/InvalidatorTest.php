<?php

namespace Spiritix\LadaCache\Tests;

class InvalidatorTest extends TestCase
{
    private $cache;

    private $invalidator;

    public function setUp()
    {
        parent::setUp();

        $this->cache = app()->make('lada.cache');
        $this->invalidator = app()->make('lada.invalidator');
    }

    public function testInvalidate()
    {
        $this->cache->set('key', ['tag1', 'tag4'], 'data');

        $this->invalidator->invalidate(['tag1', 'tag3']);

        $this->assertFalse($this->cache->has('key'));
    }
}