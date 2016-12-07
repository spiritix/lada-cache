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
     * Defines if rows should be considered as tags.
     *
     * @var bool
     */
    private $considerRows = true;

    /**
     * Initialize tagger.
     *
     * @param Reflector $reflector Reflector instance
     */
    public function __construct(Reflector $reflector)
    {
        $this->reflector = $reflector;
        $this->considerRows = (bool) config('lada-cache.consider-rows');
    }

    /**
     * Compiles and returns the tags.
     *
     * @return array
     */
    public function getTags()
    {
        // Get affected database and tables, add prefixes
        $database = $this->prefix($this->reflector->getDatabase(), self::PREFIX_DATABASE);

        // Get affected tables, don't add prefixes yes
        $tables = $this->reflector->getTables();

        // If rows should not be considered, we don't have to differ between specific and unspecific queries
        // We can simply prefix all tables with the database and return them
        if ($this->considerRows === false) {
            return $this->prefix($tables, $database);
        }

        // Get affected rows as multidimensional array per table
        $rows = $this->reflector->getRows();

        // Create the table tags with corresponding prefix
        // Depending on whether the queries are specific or not
        $tags = $this->getTableTags($tables, $rows);

        // Then we loop trough all these tags and add a tag for each row
        // Consisting of the prefixed table and the row with prefix
        foreach ($tables as $table) {

            if (!isset($rows[$table])) {
                continue;
            }

            $tablePrefix = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
            $rowPrefix = $this->prefix(self::PREFIX_ROW, $tablePrefix);

            $tags = array_merge($tags, $this->prefix($rows[$table], $rowPrefix));
        }

        return $this->prefix($tags, $database);
    }

    /**
     * Returns the prefixed table tags for a set of tables and rows.
     *
     * @param array $tables The tables to be tagged
     * @param array $rows   A multidimensional array containing the rows per table
     *
     * @return array
     */
    private function getTableTags($tables, $rows)
    {
        $tags = [];
        $type = $this->reflector->getType();

        foreach ($tables as $table) {
            $isSpecific = (isset($rows[$table]) && !empty($rows[$table]));

            // These types of queries require a specific tag
            if (($type === Reflector::QUERY_TYPE_SELECT && $isSpecific) ||
                ($type === Reflector::QUERY_TYPE_UPDATE && !$isSpecific) ||
                ($type === Reflector::QUERY_TYPE_DELETE && !$isSpecific) ||
                ($type === Reflector::QUERY_TYPE_TRUNCATE)) {

                $tags[] = $this->prefix($table, self::PREFIX_TABLE_SPECIFIC);
            }

            // While these ones require an unspecific one
            if (($type === Reflector::QUERY_TYPE_SELECT && !$isSpecific) ||
                ($type === Reflector::QUERY_TYPE_UPDATE) ||
                ($type === Reflector::QUERY_TYPE_DELETE) ||
                ($type === Reflector::QUERY_TYPE_INSERT)) {

                $tags[] = $this->prefix($table, self::PREFIX_TABLE_UNSPECIFIC);
            }
        }

        return $tags;
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