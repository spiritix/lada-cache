<?php

namespace Spiritix\LadaCache\Tests;

use ReflectionClass;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Invalidator;

class InvalidatorTest extends TestCase
{
    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var Invalidator
     */
    private $invalidator;

    public function setUp(): void
    {
        parent::setUp();

        $this->cache = app()->make('lada.cache');
        $this->invalidator = app()->make('lada.invalidator');

        $this->cache->flush();
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

    public function testCacheStateWhenInvalideIsCalledInDistributedSystem(): void
    {
        // Make reflection class to call private methods.
        $reflectionClass = new ReflectionClass($this->invalidator);
        $getHashesAndDeleteTagsFunction = $reflectionClass->getMethod('getHashesAndDeleteTags');
        $getHashesAndDeleteTagsFunction->setAccessible(true);

        $deleteItemsFunction = $reflectionClass->getMethod('deleteItems');
        $deleteItemsFunction->setAccessible(true);

        $this->cache->set('key1', ['tag1'], 'data');
        $this->cache->set('key2', ['tag2'], 'data');

        $tags = ['tag1', 'tag2'];

        // Replicate the functionality of the 'invalidate' function.
        // Start --------------------------------------------------------------------------------
        $hashes = $getHashesAndDeleteTagsFunction->invoke($this->invalidator, $tags);

        $this->assertFalse($this->cache->has('tag2'));

        // Simulate distributed system, that adds cache key in the middle of process.
        // This added cache, has a tag that will be deleted by the first process.
        $this->cache->set('key3', ['tag2'], 'data');

        $deleteItemsFunction->invoke($this->invalidator, $hashes);

        // End ----------------------------------------------------------------------------------

        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
        $this->assertFalse($this->cache->has('tag1'));

        // Check key and tag from the simulate distributed system still exists.
        $this->assertTrue($this->cache->has('key3'));
        $this->assertTrue($this->cache->has('tag2'));
    }

    public function testCacheHitsValidateKeyInTags(): void
    {
        $this->cache->set('key1', ['tag2'], 'data'); // <-- Bug, simulate 'tag1' missing from key1
        $this->cache->set('key2', ['tag1', 'tag2'], 'data');

        // Simulate cache hit on Key1
        // Start --------------------------------------------------------------------------------
        $data = $this->cache->get('key1');
        $this->assertSame('data', $data);
        $this->cache->setCacheTagsForKey('key1', ['tag1', 'tag2']); // <-- Validate tags for key
        // End ----------------------------------------------------------------------------------

        // Invalidate cache.
        $this->invalidator->invalidate(['tag1']);

        // Check cache is invalidated:
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }
}
