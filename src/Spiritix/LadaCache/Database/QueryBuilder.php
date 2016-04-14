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

namespace Spiritix\LadaCache\Database;

use Illuminate\Database\Query\Builder;
use ReflectionException;
use Spiritix\LadaCache\Debug\CacheCollector;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Manager;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;

/**
 * Overrides Laravel's query builder class.
 *
 * @package Spiritix\LadaCache\Database
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class QueryBuilder extends Builder
{
    /**
     * Run the query as a "select" statement against the connection.
     *
     * @return array
     */
    protected function runSelect()
    {
        $result = null;

        // Check if a debug bar collector is available
        // If so, initialize it and start measuring
        try {
            /* @var CacheCollector $collector */
            $collector = app()->make('lada.collector');
            $collector->startMeasuring();
        }
        catch (ReflectionException $e) {
            $collector = null;
        }

        $reflector = app()->make(Reflector::class, [$this]);
        $manager = app()->make(Manager::class, [$reflector]);

        // Check if query should be cached
        if (!$manager->shouldCache()) {
            return parent::runSelect();
        }

        // Resolve the actual cache
        $cache = app()->make('lada.cache');

        $hasher = app()->make(Hasher::class, [$reflector]);
        $tagger = app()->make(Tagger::class, [$reflector, false]);

        // Build hash for SQL query
        $key = $hasher->getHash();

        // Check if a cached version is available
        if ($cache->has($key)) {
            $result = $cache->get($key);
        }

        // If a collector is available, end measuring
        if ($collector !== null) {

            $collector->endMeasuring(
                ($result !== null) ? CacheCollector::TYPE_HIT : CacheCollector::TYPE_MISS,
                $hasher->getHash(),
                $tagger->getTags(),
                $reflector->getSql(),
                $reflector->getParameters()
            );
        }

        // If no cached version is available, run the actual select
        // Put the results of the query into the cache
        if ($result === null) {

            $result = parent::runSelect();
            $cache->set($key, $tagger->getTags(), $result);
        }

        return $result;
    }

    /**
     * Insert a new record into the database.
     *
     * @param  array $values
     *
     * @return bool
     */
    public function insert(array $values)
    {
        $this->invalidateQuery();

        return parent::insert($values);
    }

    /**
     * Insert a new record and get the value of the primary key.
     *
     * @param  array  $values
     * @param  string $sequence
     *
     * @return int
     */
    public function insertGetId(array $values, $sequence = null)
    {
        $this->invalidateQuery();

        return parent::insertGetId($values, $sequence);
    }

    /**
     * Update a record in the database.
     *
     * @param  array $values
     *
     * @return int
     */
    public function update(array $values)
    {
        $this->invalidateQuery();

        return parent::update($values);
    }

    /**
     * Delete a record from the database.
     *
     * @param  null|int $id
     *
     * @return int
     */
    public function delete($id = null)
    {
        $this->invalidateQuery();

        return parent::delete($id);
    }

    /**
     * Run a truncate statement on the table.
     */
    public function truncate()
    {
        $this->invalidateQuery();

        parent::truncate();
    }

    /**
     * Invalidates items in the cache based on the current query.
     */
    private function invalidateQuery()
    {
        $invalidator = app()->make('lada.invalidator');

        $reflector = app()->make(Reflector::class, [$this]);
        $tagger = app()->make(Tagger::class, [$reflector]);

        $invalidator->invalidate($tagger->getTags());
    }
}