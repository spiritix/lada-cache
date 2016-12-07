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

use RuntimeException;
use Spiritix\LadaCache\Database\QueryBuilder;

/**
 * Query builder reflector provides information about an Eloquent query builder object.
 *
 * @package Spiritix\LadaCache\Reflector
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class Reflector
{
    /**
     * Query of type "SELECT".
     */
    const QUERY_TYPE_SELECT = 'select';

    /**
     * Query of type "INSERT".
     */
    const QUERY_TYPE_INSERT = 'insert';

    /**
     * Query of type "UPDATE".
     */
    const QUERY_TYPE_UPDATE = 'update';

    /**
     * Query of type "DELETE".
     */
    const QUERY_TYPE_DELETE = 'delete';

    /**
     * Query of type "TRUNCATE".
     */
    const QUERY_TYPE_TRUNCATE = 'truncate';
    /**
     * Since the query builder doesn't know about the related model, we have no way to figure out the name of the
     * primary key column. If someone is not using this value as primary key column it won't break anything, it just
     * wont consider the row ID's when creating the cache tags.
     *
     * @todo Get the primary key column from the model.
     */
    const PRIMARY_KEY_COLUMN = 'id';

    /**
     * Query builder instance.
     *
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * Values to be saved on the model.
     *
     * @var array
     */
    private $values = [];

    /**
     * The sql operation being performed.
     *
     * @var string
     */
    private $sqlOperation = self::QUERY_TYPE_SELECT;

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
     * {@inheritdoc}
     *
     * @return string
     */
    public function getDatabase()
    {
        return $this->queryBuilder
            ->getConnection()
            ->getDatabaseName();
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getTables()
    {
        // Get main table
        $tables = [$this->queryBuilder->from];

        // Add possible join tables
        $joins = $this->queryBuilder->joins ? : [];
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
     * @return array
     */
    public function getRows()
    {
        $rows = [];

        $wheres = $this->queryBuilder->wheres ? : [];

        foreach ($wheres as $where) {

            // Skip unsupported clauses
            if (!isset($where['column'])) {
                continue;
            }

            // If it doesn't contain the table name assume it's the "FROM" table
            if (strpos($where['column'], '.') === false) {
                $where['column'] = implode('.', [$this->queryBuilder->from, $where['column']]);
            }

            list($table, $column) = $this->splitTableAndColumn($where['column']);

            if (!isset($rows[$table])) {
                $rows[$table] = [];
            }

            // Make sure that the where clause applies for the primary key column
            if ($column !== self::PRIMARY_KEY_COLUMN) {
                continue;
            }

            if ($where['type'] === 'Basic') {

                if ($where['operator'] === '=' && is_numeric($where['value'])) {
                    $rows[$table][] = $where['value'];
                }
            }
            elseif ($where['type'] === 'In') {
                $rows[$table] += $where['values'];
            }
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getSql()
    {
        $compileFunction = $this->getCompileFunction();

        $grammar = $this->queryBuilder->getGrammar();

        return call_user_func_array([$grammar, $compileFunction], [
            'builder'  => $this->queryBuilder,
            'values'   => $this->values,
            'sequence' => '',
        ]);
    }

    /**
     * Set values to be modifier on the model.
     *
     * @param array $values
     *
     * @return $this
     */
    public function setValues(array $values)
    {
        $this->values = $values;

        return $this;
    }

    /**
     * Sets the sql operation.
     *
     * @param string $sqlOperation
     *
     * @return $this
     */
    public function setSqlOperation($sqlOperation)
    {
        $this->sqlOperation = $sqlOperation;

        return $this;
    }

    /**
     * Determine the type of query in the builder.
     *
     * @param string $queryType One of: select, insert, update, delete, truncate
     *
     * @return bool
     */
    public function isQueryOfType($queryType)
    {
        $allowedQueryTypes = [
            self::QUERY_TYPE_SELECT,
            self::QUERY_TYPE_INSERT,
            self::QUERY_TYPE_UPDATE,
            self::QUERY_TYPE_DELETE,
            self::QUERY_TYPE_TRUNCATE,
        ];

        if (!in_array(strtolower($queryType), $allowedQueryTypes)) {
            throw new RuntimeException('Not intended to be used like this.');
        }

        $sql = $this->getSql();

        /**
         * Edge case for sqlite not supporting the truncate query, instead issues 2 delete queries,
         * one on the table sqlite_sequence.
         */
        if (is_array($sql)) {
            $sql = implode(';', array_keys($sql));
        }

        $sqlString = strtolower(trim($sql));

        return starts_with($sqlString, $queryType);
    }

    /**
     * Determine if the builder holds a "SELECT" query.
     *
     * @return bool
     */
    public function isSelectQuery()
    {
        return $this->isQueryOfType(self::QUERY_TYPE_SELECT);
    }

    /**
     * Determine if the builder holds a "INSERT" query.
     *
     * @return bool
     */
    public function isInsertQuery()
    {
        return $this->isQueryOfType(self::QUERY_TYPE_INSERT);
    }

    /**
     * Determine if the builder holds a "UPDATE" query.
     *
     * @return bool
     */
    public function isUpdateQuery()
    {
        return $this->isQueryOfType(self::QUERY_TYPE_UPDATE);
    }

    /**
     * Determine if the builder holds a "TRUNCATE" query.
     *
     * @return bool
     */
    public function isTruncateQuery()
    {
        return $this->isQueryOfType(self::QUERY_TYPE_TRUNCATE);
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    public function getParameters()
    {
        return $this->queryBuilder->getBindings();
    }

    /**
     * Determine if the builder is specific to the provided table.
     *
     * A table is specific if we know the primary keys that it "touches".
     *
     * @param string $table
     *
     * @return bool
     */
    public function isSpecific($table)
    {
        $result = [];
        $rows = $this->getRows();

        if (isset($rows[$table])) {
            $result = $rows[$table];
        }

        return !empty($result);
    }
    /**
     * Splits an SQL column identifier into table and column.
     *
     * @param string $sqlString SQL column identifier
     *
     * @return array [table|null, column]
     */
    protected function splitTableAndColumn($sqlString)
    {
        // Most column identifiers don't contain a database or table name
        // In this case just return what we've got
        if (strpos($sqlString, '.') === false) {
            return [null, $sqlString];
        }

        $parts = explode('.', $sqlString);

        // If we have three parts, the identifier also contains the database name
        if (count($parts) === 3) {
            $table = $parts[1];
        }
        // Otherwise it contains table and column
        else {
            $table = $parts[0];
        }

        // Column is always the last part
        $column = end($parts);

        return [$table, $column];
    }

    /**
     * Get the mysql laravel grammar compile function.
     *
     * @return string
     */
    private function getCompileFunction()
    {
        switch (strtolower($this->sqlOperation)) {
            case self::QUERY_TYPE_INSERT:
                return 'compileInsert';
                break;

            case 'insertgetid':
                return 'compileInsertGetId';
                break;

            case self::QUERY_TYPE_UPDATE:
                return 'compileUpdate';
                break;

            case self::QUERY_TYPE_DELETE:
                return 'compileDelete';
                break;

            case self::QUERY_TYPE_TRUNCATE:
                return 'compileTruncate';
                break;

            default:
                return 'compileSelect';
                break;
        }
    }
}
