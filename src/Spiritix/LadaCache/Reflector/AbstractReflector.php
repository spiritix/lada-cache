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
 * Reflector abstract.
 *
 * @package Spiritix\LadaCache\Reflector
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
abstract class AbstractReflector
{
    /**
     * Database prefix.
     */
    const PREFIX_DATABASE = 'd:';

    /**
     * Table prefix.
     */
    const PREFIX_TABLE = 't:';

    /**
     * Row prefix.
     */
    const PREFIX_ROW = 'r:';

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
     * @return array
     */
    abstract protected function getRows();

    /**
     * Returns an array of all tags for current query.
     *
     * If no rows are available the tags must look like this:
     * [TABLE_PREFIX . TABLE1, TABLE_PREFIX . TABLE2]
     *
     * If rows are available, the tags must look like this:
     * [TABLE_PREFIX . TABLE1 . ROW_PREFIX . ROW1, TABLE_PREFIX . TABLE1 . ROW_PREFIX . ROW2,
     *  TABLE_PREFIX . TABLE2 . ROW_PREFIX . ROW1, TABLE_PREFIX . TABLE2 . ROW_PREFIX . ROW2]
     *
     * @return array
     */
    public function getTags()
    {
        $tables = $this->prefix($this->getTables(), self::PREFIX_TABLE);

        $rows = $this->getRows();
        if (empty($rows)) {

            return $tables;
        }

        $tags = [];
        foreach ($tables as $table) {
            $tags += $this->prefix($rows, $table . self::PREFIX_ROW);
        }

        return $tags;
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
                return $prefix . $item;
            }, $value);
        }

        return $prefix . $value;
    }
}