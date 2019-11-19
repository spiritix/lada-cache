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
     * Query builder instance.
     *
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * The SQL operation being performed.
     *
     * @var string
     */
    private $sqlOperation;

    /**
     * The values to be saved.
     *
     * @var array
     */
    private $values = [];

    /**
     * Initialize reflector.
     *
     * @param QueryBuilder $queryBuilder
     * @param string       $sqlOperation The SQL operation to be performed
     * @param array        $values       The values to be saved
     */
    public function __construct(QueryBuilder $queryBuilder, $sqlOperation = self::QUERY_TYPE_SELECT, $values = [])
    {
        $this->queryBuilder = $queryBuilder;
        $this->sqlOperation = $sqlOperation;
        $this->values = $values;
    }

    /**
     * Returns the database name.
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
     * Returns all affected tables, including joined ones.
     *
     * @return array
     */
    public function getTables()
    {
        // Get main table
        $tables = [$this->queryBuilder->from];

        // Add possible join tables
        $joins = $this->queryBuilder->joins ?: [];
        foreach ($joins as $join) {

            if (!in_array($join->table, $tables) && is_string($join->table)) {
                $tables[] = $join->table;
            }
        }

        $this->getTablesFromWhere($this->queryBuilder, $tables);

        return $tables;
    }

    /**
     * Get Table Names From Where Exists, Not Exists (whereHas/whereDoesnthave builder syntax)
     *
     * @param QueryBuilder $queryBuilder
     */
    private function getTablesFromWhere(QueryBuilder $queryBuilder, &$tables) {
        if (!isset($queryBuilder->wheres)) {
            return;
        }
        $wheres = $queryBuilder->wheres ?: [];
        foreach ($wheres as $where) {
            if ($where['type'] == 'Exists' || $where['type'] == 'NotExists') {
                $tables[] = $where['query']->from;

                // Add possible join tables
                $joins = $where['query']->joins ?: [];
                foreach ($joins as $join) {

                    if (!in_array($join->table, $tables) && is_string($join->table)) {
                        $tables[] = $join->table;
                    }
                }
            }
            if (isset($where['query'])) {
                $this->getTablesFromWhere($where['query'], $tables);
            }
        }
    }

    /**
     * Returns all affected rows as a multidimensional array, split up by table.
     *
     * @return array
     */
    public function getRows()
    {
        $rows = [];
        $wheres = $this->queryBuilder->wheres ?: [];

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

            // Make sure that the where clause applies for the primary key column
            if ($column !== $this->queryBuilder->model->getKeyName()) {
                continue;
            }

            // Initialize a set for the current table
            if (!isset($rows[$table])) {
                $rows[$table] = [];
            }

            // Add the rows to the current table set
            if ($where['type'] === 'Basic') {
                if ($where['operator'] === '=' && is_numeric($where['value'])) {
                    $rows[$table][] = $where['value'];
                }
            }
            else if ($where['type'] === 'In') {
                $rows[$table] += $where['values'];
            }
        }

        return $rows;
    }

    /**
     * Returns the type of the query.
     *
     * @return string
     */
    public function getType()
    {
        $sql = $this->getSql();

        $type = strtok(strtolower(trim($sql)), ' ');
        $type = preg_replace('/[^a-z]/i', '', $type);

        $allowedTypes = [
            self::QUERY_TYPE_SELECT,
            self::QUERY_TYPE_INSERT,
            self::QUERY_TYPE_UPDATE,
            self::QUERY_TYPE_DELETE,
            self::QUERY_TYPE_TRUNCATE,
        ];

        if (!in_array($type, $allowedTypes)) {
            throw new RuntimeException('Invalid query type');
        }

        return $type;
    }

    /**
     * Returns the compiled SQL string.
     *
     * @return string
     */
    public function getSql()
    {
        $compileFunction = $this->getCompileFunction();
        $grammar = $this->queryBuilder->getGrammar();

        $sql = call_user_func_array([$grammar, $compileFunction], [
            'builder'  => $this->queryBuilder,
            'values'   => $this->values,
            'sequence' => '',
        ]);

        // For some DBMS like SQLite Laravel issues two queries as an array instead of one string
        // This is seriously not ok, but anyway...
        if (is_array($sql)) {
            $sql = implode('; ', array_keys($sql));
        }

        return $sql;
    }

    /**
     * Returns the query parameters.
     *
     * @return array
     */
    public function getParameters()
    {
        return $this->queryBuilder->getBindings();
    }

    /**
     * Splits an SQL column identifier into table and column.
     *
     * @param string $sqlString SQL column identifier
     *
     * @return array [table|null, column]
     */
    private function splitTableAndColumn($sqlString)
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
     * Get the Eloquent grammar compile function.
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
