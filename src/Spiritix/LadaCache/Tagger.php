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
     * Table specific tag prefix.
     */
    const PREFIX_TABLE_SPECIFIC = ':table_specific:';

    /**
     * Table unspecific tag prefix.
     */
    const PREFIX_TABLE_UNSPECIFIC = ':table_unspecific:';

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
        $database = $this->prefix($this->reflector->getDatabase(), self::PREFIX_DATABASE);

        // If no rows are available or rows should not be considered
        // Let's just return the database and table tags
        $rows = $this->reflector->getRows();
        $tables = $this->reflector->getTables();

        // Generating table tags
        $prefixedTables = [];
        foreach ($tables as $table) {
            $isSpecific = $this->reflector->isSpecific($table);

            if ($this->reflector->isSelectQuery() && $isSpecific) {
                $prefixedTables[] = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
            } else if (
                $this->reflector->isTruncateQuery() ||
                (!$this->reflector->isSelectQuery() && !$this->reflector->isInsertQuery() && !$isSpecific)
            ) {
                $prefixedTables[] = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
                $prefixedTables[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
            } else {
                $prefixedTables[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
            }
        }

        if ($this->considerRows === false) {
            return $this->prefix($tables, $database);
        }

        // Else loop trough tables and create a tag for each row
        foreach ($tables as $table) {
            $prefixedTable = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);

            $tags = array_merge($tags, $this->prefix($rows[$table] ?? [], $this->prefix(self::PREFIX_ROW, $prefixedTable)));
        }

        // Add tables to tags if requested
        if ($this->considerTables) {
            $tags = array_merge($prefixedTables, $tags);
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
            return array_map(function ($item) use ($prefix) {
                return $this->prefix($item, $prefix);
            }, $value);
        }

        return $prefix . $value;
    }
}