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

use ReflectionException;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Debug\CacheCollector;

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
     * Values to be saved on the model.
     *
     * @var array
     */
    private $values = [];

    /**
     * The sql operation being performed.
     *
     * @var string
     */
    private $sqlOperation = 'select';

    /**
     * Collector instance.
     *
     * @var null|CacheCollector
     */
    private $collector = null;

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
     * Get values that should be modifier on the model.
     *
     * @return array
     */
    public function getValues()
    {
        return $this->values;
    }

    /**
     * Set values to be modifier on the model.
     *
     * @param array $values
     *
     * @return $this
     */
    public function setValues(array $values)
    {
        $this->values = $values;

        return $this;
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

        /* @var Reflector $reflector */
        $reflector = app()->make(Reflector::class, [$this->builder]);

        /* @var Manager $manager */
        $manager = app()->make(Manager::class, [$reflector]);

        // If cache is disabled, abort already here to save time
        if (!$manager->shouldCache()) {
            return $queryClosure();
        }

        /* @var Hasher $hasher */
        $hasher = app()->make(Hasher::class, [$reflector]);

        /* @var Tagger $tagger */
        $tagger = app()->make(Tagger::class, [$reflector, false]);

        $result = null;
        $key = $hasher->getHash();

        // Check if a cached version is available
        if ($this->cache->has($key)) {
            $result = $this->cache->get($key);
        }

        $action = ($result === null) ? 'Miss' : 'Hit';

        // If not, execute the query closure and cache the result
        if ($result === null) {
            $result = $queryClosure();

            $this->cache->set($key, $tagger->getTags(), $result);
        }

        $this->destructCollector($reflector, $tagger, $key, $action);

        $this->resetValues();
        $this->resetSqlOperation();

        return $result;
    }

    /**
     * Invalidates a query.
     *
     * @param string $statementType The type of the statement that caused the invalidation
     */
    public function invalidateQuery($statementType)
    {
        $this->constructCollector();

        /* @var Reflector $reflector */
        $reflector = app()->make(Reflector::class, [$this->builder]);
        $reflector->setSqlOperation($this->getSqlOperation())
            ->setValues($this->getValues());

        /* @var Tagger $tagger */
        $tagger = app()->make(Tagger::class, [$reflector, true]);

        $hashes = $this->invalidator->invalidate($tagger->getTags());

        $action = 'Invalidation (' .  $statementType . ')';
        $this->destructCollector($reflector, $tagger, $hashes, $action);

        $this->resetValues();
        $this->resetSqlOperation();
    }

    /**
     * Resets the sql operation being performed.
     */
    public function resetSqlOperation()
    {
        $this->sqlOperation = 'select';
    }

    /**
     * Get the sql operation being performed.
     *
     * @return string
     */
    public function getSqlOperation(): string
    {
        return $this->sqlOperation;
    }

    /**
     * Sets the sql operation.
     *
     * @param string $sqlOperation
     *
     * @return $this
     */
    public function setSqlOperation(string $sqlOperation)
    {
        $this->sqlOperation = $sqlOperation;

        return $this;
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
        catch (ReflectionException $e) {
            $this->collector = null;
        }
    }

    /**
     * Destructs the collector.
     *
     * @param Reflector    $reflector The reflector instance
     * @param Tagger       $tagger    The tagger instance
     * @param string|array $hashes    The hash(es) for the executed query
     * @param string       $action    The action that happened
     */
    private function destructCollector(Reflector $reflector, $tagger, $hashes, $action)
    {
        if ($this->collector === null) {
            return;
        }

        $this->collector->endMeasuring(
            $action,
            is_array($hashes) ? $hashes : [$hashes],
            $tagger->getTags(),
            $reflector->getSql(),
            $reflector->getParameters()
        );
    }

    /**
     * Resets values.
     *
     * @return $this
     */
    private function resetValues()
    {
        $this->values = [];

        return $this;
    }
}