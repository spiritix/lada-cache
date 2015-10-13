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

use Spiritix\LadaCache\Database\QueryBuilder;

/**
 * Todo
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Reflector
{
    /**
     * Table tag prefix
     */
    const TAG_TABLE_PREFIX = 't:';

    /**
     * Column tag prefix
     */
    const TAG_COLUMN_PREFIX = 'c:';

    /**
     * Row tag prefix
     */
    const TAG_ROW_PREFIX = 'r:';

    /**
     * Query builder instance.
     *
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * Initialize reflector.
     *
     * @param QueryBuilder $queryBuilder
     */
    public function __construct(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
    }

    /**
     * Returns a hash of the current query.
     *
     * @return string
     */
    public function getHash()
    {
        return md5($this->queryBuilder->toSql());
    }

    /**
     * Returns an array of all prefixed tags.
     *
     * @return array
     */
    public function getTags()
    {
        return array_merge(
            $this->getTables(),
            $this->getColumns(),
            $this->getRows()
        );
    }

    /**
     * Returns prefixed affected tables.
     *
     * @return array
     */
    private function getTables()
    {
        $tables = array_merge(
            [$this->queryBuilder->from],
            $this->queryBuilder->joins
        );

        return array_map(function($table) {
            return self::TAG_TABLE_PREFIX . $table;
        }, $tables);
    }

    /**
     * Returns prefixed affected columns.
     *
     * @return array
     */
    private function getColumns()
    {
        $columns = $this->queryBuilder->columns;

        return array_map(function($column) {
            return self::TAG_COLUMN_PREFIX . $column;
        }, $columns);
    }

    /**
     * Returns prefixed affected rows.
     *
     * @return array
     */
    private function getRows()
    {
        $rows = []; // TODO

        return array_map(function($row) {
            return self::TAG_ROW_PREFIX . $row;
        }, $rows);
    }
}