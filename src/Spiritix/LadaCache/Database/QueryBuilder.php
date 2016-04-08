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
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Manager;
use Spiritix\LadaCache\Reflector\QueryBuilder as QueryBuilderReflector;
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
     * Check if a cached version is available and return it, otherwise add result to cache.
     *
     * @return array
     */
    protected function runSelect()
    {
        $cache_ttl = null;
        if (isset($this->lada_cache_ttl)) {
            $cache_ttl = $this->lada_cache_ttl;
        }

        $reflector = new QueryBuilderReflector($this);
        $manager = new Manager($reflector);

        // Check if query should be cached
        if (!$manager->shouldCache()) {
            return parent::runSelect();
        }

        // Resolve the actual cache
        $cache = app()->make('lada.cache');

        $hasher = new Hasher($reflector);
        $tagger = new Tagger($reflector, false);

        // Check if a cached version is available
        $key = $hasher->getHash($cache_ttl);
        if ($cache->has($key)) {

            return $cache->get($key);
        }

        // If not execute query and add to cache
        $result = parent::runSelect();
        $cache->set($key, $tagger->getTags(), $result, $cache_ttl);

        return $result;
    }

    /**
     * Delete a record from the database.
     *
     * Unfortunately Laravel does not fire the deleted event for models if one uses the ->detach() method.
     * Therefore we have to hook into the query builder delete method here to prevent this issue.
     *
     * @param  mixed $id
     *
     * @return int
     */
    public function delete($id = null)
    {
        $invalidator = app()->make('lada.invalidator');

        $tagger = new Tagger(new QueryBuilderReflector($this));
        $invalidator->invalidate($tagger->getTags());

        return parent::delete($id);
    }
}