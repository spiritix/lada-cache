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

use Spiritix\LadaCache\Database\QueryBuilder as EloquentQueryBuilder;

/**
 * Query builder reflector provides information about an Eloquent query builder object.
 *
 * @package Spiritix\LadaCache\Reflector
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class QueryBuilder
{
    /**
     * Database tag prefix.
     */
    const TAG_DATABASE_PREFIX = 'd:';

    /**
     * Table tag prefix.
     */
    const TAG_TABLE_PREFIX = 't:';

    /**
     * Column tag prefix.
     */
    const TAG_COLUMN_PREFIX = 'c:';

    /**
     * Row tag prefix.
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
     * @param EloquentQueryBuilder $queryBuilder
     */
    public function __construct(EloquentQueryBuilder $queryBuilder)
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
        // Never remove the database from the identifier
        // Query doesn't necessarily include it, will cause cache conflicts
        $identifier = $this->getDatabase() . $this->queryBuilder->toSql();

        return md5($identifier);
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
     * Returns prefixed name of target database.
     *
     * @return string
     */
    public function getDatabase()
    {
        return self::TAG_DATABASE_PREFIX . $this->queryBuilder
            ->getConnection()
            ->getDatabaseName();
    }

    /**
     * Returns prefixed affected tables.
     *
     * @return array
     */
    private function getTables()
    {
        // Get main table
        $tables = [$this->queryBuilder->from];

        // Add possible join tables
        $joins = $this->queryBuilder->joins ?: [];
        foreach ($joins as $join) {

            if (!in_array($join->table, $tables)) {
                $tables[] = $join->table;
            }
        }

        // Add prefixes
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

        // Add prefixes
        return array_map(function($column) {
            return self::TAG_COLUMN_PREFIX . $column;
        }, $columns);
    }

    /**
     * Returns prefixed affected rows.
     *
     * @todo This must be implemented ASAP.
     *
     * @return array
     */
    private function getRows()
    {
        $rows = [];

        // Add prefixes
        return array_map(function($row) {
            return self::TAG_ROW_PREFIX . $row;
        }, $rows);
    }
}