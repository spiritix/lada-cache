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
        // Check if cache is active
        if (config('lada-cache.active') === false) {
            return parent::runSelect();
        }

        // Resolve the actual cache
        $cache = app()->make('lada.cache');

        // Initialize all the utils
        $reflector = new QueryBuilderReflector($this);
        $hasher = new Hasher($reflector);
        $tagger = new Tagger($reflector, false);

        // Check if a cached version is available
        $key = $hasher->getHash();
        if ($cache->has($key)) {

            return $cache->get($key);
        }

        // If not execute query and add to cache
        $result = parent::runSelect();
        $cache->set($key, $tagger->getTags(), $result);

        // We do not return $cache->get() here
        // This would cause a separate cache request
        return $result;
    }
}