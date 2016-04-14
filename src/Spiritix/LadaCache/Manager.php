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

/**
 * Manager decides whether a query should be cached or not.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Manager
{
    /**
     * Reflector instance.
     *
     * @var Reflector
     */
    private $reflector;

    /**
     * True if cache is enabled.
     *
     * @var bool
     */
    private $cacheActive;

    /**
     * Contains a list of all tables that must be cached.
     *
     * @var array
     */
    private $includeTables = [];

    /**
     * Contains a list of all tables that must not be cached.
     *
     * @var array
     */
    private $excludeTables = [];

    /**
     * Initialize manager.
     *
     * @param Reflector $reflector
     */
    public function __construct(Reflector $reflector)
    {
        $this->reflector = $reflector;

        $this->cacheActive = (bool) config('lada-cache.active');
        $this->includeTables = (array) config('lada-cache.include-tables');
        $this->excludeTables = (array) config('lada-cache.exclude-tables');
    }

    /**
     * Checks if the query should be cached.
     *
     * @return bool
     */
    public function shouldCache()
    {
        if ($this->cacheEnabled() && $this->tablesCachable()) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the cache is enabled.
     *
     * @return bool
     */
    private function cacheEnabled()
    {
        return ($this->cacheActive === true);
    }

    /**
     * Checks if the tables returned by the reflector are cachable.
     *
     * If "include-tables" are available, it will only return true if ALL affected tables exists in "include-tables".
     * If "exclude-tables" are enabled, it will only return false if ANY affected table exists in "exclude-tables".
     *
     * @return bool
     */
    private function tablesCachable()
    {
        $tables = $this->reflector->getTables();

        if ($this->isInclusive()) {
            foreach ($tables as $table) {
                if (!$this->tableIncluded($table)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($tables as $table) {
            if ($this->tableExcluded($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if a table exists in "include-tables".
     *
     * @param string $table Table name
     *
     * @return bool
     */
    private function tableIncluded($table)
    {
        return in_array($table, $this->includeTables);
    }

    /**
     * Checks if a table exists in "exclude-tables".
     *
     * @param string $table Table name
     *
     * @return bool
     */
    private function tableExcluded($table)
    {
        return in_array($table, $this->excludeTables);
    }

    /**
     * Checks if mode is set to inclusive (at least one table available in "include-tables").
     *
     * @return bool
     */
    private function isInclusive()
    {
        return !empty($this->includeTables);
    }
}