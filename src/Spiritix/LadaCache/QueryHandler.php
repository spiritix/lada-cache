<?php
/**
 * This file is part of the spiritix/lada-cache package.
 *
 * @copyright Copyright (c) Matthias Isler <mi@matthias-isler.ch>
 * @license   MIT
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Spiritix\LadaCache;

use Exception;
use Spiritix\LadaCache\Debug\CacheCollector;
use Spiritix\LadaCache\Database\QueryBuilder;

/**
 * Query handler is Eloquent's gateway to access the cache.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class QueryHandler
{
    /**
     * Cache instance.
     *
     * @var Cache
     */
    private $cache;

    /**
     * Invalidator instance.
     *
     * @var Invalidator
     */
    private $invalidator;

    /**
     * Query builder instance.
     *
     * @var null|QueryBuilder
     */
    private $builder = null;

    /**
     * Collector instance.
     *
     * @var null|CacheCollector
     */
    private $collector = null;

    /**
     * Collection of subquery tags.
     *
     * @var array
     */
    private $subQueryTags = [];

    /**
     * Initialize the query handler.
     *
     * @param Cache          $cache
     * @param Invalidator    $invalidator
     */
    public function __construct(Cache $cache, Invalidator $invalidator)
    {
        $this->cache = $cache;
        $this->invalidator = $invalidator;
    }

    /**
     * Set the query builder instance.
     *
     * @param QueryBuilder $builder
     *
     * @return QueryHandler
     */
    public function setBuilder(QueryBuilder $builder)
    {
        $this->builder = $builder;

        return $this;
    }

    /**
     * Collects the tags of a subquery for later handling of the main query.
     */
    public function collectSubQueryTags()
    {
        /* @var Reflector $reflector */
        $reflector = new Reflector($this->builder);

        /* @var Manager $manager */
        $manager = new Manager($reflector);

        // Skip if caching is disabled for current query
        if (!$manager->shouldCache()) {
            return;
        }

        /* @var Tagger $tagger */
        $tagger = new Tagger($reflector);

        // Add tags to collection
        $this->subQueryTags = array_unique(array_merge(
            $this->subQueryTags,
            $tagger->getTags()
        ));
    }

    /**
     * Caches a query and returns its result.
     *
     * @param callable $queryClosure A closure which executes the query and returns the result
     *
     * @return array
     */
    public function cacheQuery($queryClosure)
    {
        $this->constructCollector();

        // Make sure to reset the sub query tags already here
        // Otherwise we'll get into trouble if caching is disabled for main query but not for subquery
        $subQueryTags = $this->subQueryTags;
        $this->subQueryTags = [];

        /* @var Reflector $reflector */
        $reflector = new Reflector($this->builder);

        /* @var Manager $manager */
        $manager = new Manager($reflector);

        // If cache is disabled, abort already here to save time
        if (!$manager->shouldCache()) {
            return $queryClosure();
        }

        /* @var Hasher $hasher */
        $hasher = new Hasher($reflector);

        /* @var Tagger $tagger */
        $tagger = new Tagger($reflector);

        $result = null;
        $key = $hasher->getHash();
        $tags = array_unique(array_merge($tagger->getTags(), $subQueryTags));

        // Check if a cached version is available
        if ($this->cache->has($key)) {
            $result = $this->cache->get($key);
        }

        $action = ($result === null) ? 'Miss' : 'Hit';

        if ($result === null) {
            // Cache miss, execute the query closure and cache the result
            $result = $queryClosure();
            $this->cache->set($key, $tags, $result);
        } else {
            // Cache hit, validate cache tags on key
            $this->cache->setCacheTagsForKey($key, $tags);
        }

        $this->destructCollector($reflector, $tags, $key, $action);

        return $result;
    }

    /**
     * Invalidates a query.
     *
     * @param string $statementType The type of the statement that caused the invalidation
     * @param array $values         The values to be modified
     */
    public function invalidateQuery($statementType, $values = [])
    {
        $this->constructCollector();

        /* @var Reflector $reflector */
        $reflector = new Reflector($this->builder, $statementType, $values);

        /* @var Manager $manager */
        $manager = new Manager($reflector);

        // If cache is disabled, abort already here to save time
        if (!$manager->shouldCache()) {
            return;
        }

        /* @var Tagger $tagger */
        $tagger = new Tagger($reflector);
        $tags = $tagger->getTags();

        $hashes = $this->invalidator->invalidate($tags);

        $action = 'Invalidation (' .  $statementType . ')';
        $this->destructCollector($reflector, $tags, $hashes, $action);
    }

    /**
     * Constructs a collector instance.
     *
     * If debug bar is not available (collector service not booted), the collector will be set to null.
     */
    private function constructCollector()
    {
        try {
            $this->collector = app()->make('lada.collector');
            $this->collector->startMeasuring();
        }
        catch (Exception $e) {
            $this->collector = null;
        }
    }

    /**
     * Destructs the collector.
     *
     * @param Reflector    $reflector The reflector instance
     * @param array        $tags      The tags for the executed query
     * @param string|array $hashes    The hash(es) for the executed query
     * @param string       $action    The action that happened
     */
    private function destructCollector(Reflector $reflector, $tags, $hashes, $action)
    {
        if ($this->collector === null) {
            return;
        }

        $this->collector->endMeasuring(
            $action,
            is_array($hashes) ? $hashes : [$hashes],
            $tags,
            $reflector->getSql(),
            $reflector->getParameters()
        );
    }
}