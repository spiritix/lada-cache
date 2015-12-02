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

namespace Spiritix\LadaCache\Reflector;

/**
 * A reflector parses a "database action" (not necessarily SQL) and provides information about it.
 *
 * @package Spiritix\LadaCache\Reflector
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
abstract class AbstractReflector implements ReflectorInterface
{
    /**
     * Database prefix.
     */
    const PREFIX_DATABASE = 'tags:database:';

    /**
     * Table prefix.
     */
    const PREFIX_TABLE = ':table:';

    /**
     * Row prefix.
     */
    const PREFIX_ROW = ':row:';

    /**
     * Package configuration.
     *
     * @var array
     */
    protected $config = [];

    /**
     * Returns affected database.
     *
     * @return string
     */
    abstract protected function getDatabase();

    /**
     * Returns affected table(s) as array.
     *
     * @return array
     */
    abstract protected function getTables();

    /**
     * Returns affected row(s) as array (primary keys).
     *
     * Must return an empty array if it could not determine affected rows.
     *
     * @return array
     */
    abstract protected function getRows();

    /**
     * Set package configuration.
     *
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = $config;
    }

    /**
     * Returns an array of all tags for current action.
     *
     * @param bool $forceTables If set to true, the function will add the table tags to the row tags.
     *                          By default if rows are available, table tags will not be returned.
     *
     * @return array
     */
    public function getTags($forceTables = false)
    {
        $tags = [];
        $considerRows = (bool) $this->config['consider-rows'];

        // Get affected database and tables, add prefix
        $tables = $this->prefix($this->getTables(), self::PREFIX_TABLE);
        $database = $this->prefix($this->getDatabase(), self::PREFIX_DATABASE);

        // Check if affected rows are available or if granularity is set to not consider rows
        // In this case just use the previously prepared tables as tags
        $rows = $this->getRows();
        if (empty($rows) || $considerRows === false) {

            return $this->prefix($tables, $database);
        }

        // Now loop trough tables and create a tag for each row
        foreach ($tables as $table) {
            $tags = array_merge($tags, $this->prefix($rows, $this->prefix(self::PREFIX_ROW, $table)));
        }

        // Add tables to tags if required
        if ($forceTables) {
            $tags = array_merge($tables, $tags);
        }

        return $this->prefix($tags, $database);
    }

    /**
     * Prepends a prefix to a value.
     *
     * @param string|array $value  Either a string or an array of strings
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