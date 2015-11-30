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
class QueryBuilder extends AbstractReflector
{
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
        $database = $this->prefix($this->getDatabase(), self::PREFIX_DATABASE);
        $identifier = $database . $this->queryBuilder->toSql();

        return md5($identifier);
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getDatabase()
    {
        return $this->queryBuilder
            ->getConnection()
            ->getDatabaseName(); // Fuck this shit
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    protected function getTables()
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

        return $tables;
    }

    /**
     * {@inheritdoc}
     *
     * @todo This must be implemented ASAP.
     *
     * @return array
     */
    protected function getRows()
    {
        return [];
    }
}