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
 * Tagger creates a list of tags for a query using a reflector.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Tagger
{
    /**
     * Database tag prefix.
     */
    const PREFIX_DATABASE = 'tags:database:';

    /**
     * Table tag prefix.
     */
    const PREFIX_TABLE = ':table:';

    /**
     * Row tag prefix.
     */
    const PREFIX_ROW = ':row:';

    /**
     * Reflector instance.
     *
     * @var Reflector
     */
    private $reflector;

    /**
     * Defines if tables should be considered as tags.
     *
     * @var bool
     */
    private $considerTables = true;

    /**
     * Defines if rows should be considered as tags.
     *
     * @var bool
     */
    private $considerRows = true;

    /**
     * Initialize tagger.
     *
     * @param Reflector $reflector      Reflector instance
     * @param bool      $considerTables If tables should be considered as tags
     */
    public function __construct(Reflector $reflector, $considerTables = true)
    {
        $this->reflector = $reflector;
        $this->considerTables = $considerTables;

        $this->considerRows = (bool) config('lada-cache.consider-rows');
    }

    /**
     * Compiles and returns the tags.
     *
     * @return array
     */
    public function getTags()
    {
        $tags = [];

        // Get affected database and tables, add prefixes
        $tables = $this->prefix($this->reflector->getTables(), self::PREFIX_TABLE);
        $database = $this->prefix($this->reflector->getDatabase(), self::PREFIX_DATABASE);

        // Check if affected rows are available or if granularity is set to not consider rows
        // In this case just use the previously prepared tables as tags
        $rows = $this->reflector->getRows();
        if (empty($rows) || $this->considerRows === false) {

            return $this->prefix($tables, $database);
        }

        // Now loop trough tables and create a tag for each row
        foreach ($tables as $table) {
            $tags = array_merge($tags, $this->prefix($rows, $this->prefix(self::PREFIX_ROW, $table)));
        }

        if ($this->considerRows === true && !(empty($rows))) {
            return $this->prefix($tags, $database);
        }

        // Add tables to tags if requested
        if ($this->considerTables) {
            $tags = array_merge($tables, $tags);
        }

        return $this->prefix($tags, $database);
    }

    /**
     * Prepends a prefix to one or multiple values.
     *
     * @param string|array $value  Either a string or an array of strings.
     * @param string       $prefix The prefix to be prepended.
     *
     * @return string|array
     */
    protected function prefix($value, $prefix)
    {
        if (is_array($value)) {
            return array_map(function($item) use($prefix) {
                return $this->prefix($item, $prefix);
            }, $value);
        }

        return $prefix . $value;
    }
}